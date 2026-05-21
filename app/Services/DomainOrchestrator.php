<?php

namespace App\Services;

use App\Enums\DomainStatus;
use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use App\Services\StormWall\DTO\LetsEncryptSslData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainOrchestrator
{
    public function __construct(
        private CloudflareServiceInterface $cf,
        private StormWallServiceInterface $sw,
        private DomainWorkflowLogger $logger
    ) {}

    public function handle(Domain $domain): void
    {
        $status = DomainStatus::tryFrom((string) $domain->status) ?? DomainStatus::FAILED;

        if ($status->isTerminal()) {
            Log::channel('domain')->info('Workflow skipped (terminal)', [
                'domain_id' => $domain->id,
                'domain'    => $domain->domain,
                'status'    => $status->value,
            ]);
            return;
        }

        Log::channel('domain')->info('Workflow step START', [
            'domain_id' => $domain->id,
            'domain'    => $domain->domain,
            'step'      => $status->value,
        ]);

        try {
            match ($status) {
                DomainStatus::INIT                  => $this->createCloudflareZone($domain),
                DomainStatus::CLOUDFLARE_ZONE       => $this->routeAfterZone($domain),
                DomainStatus::STORMWALL_DOMAIN      => $this->createOrUpdateCloudflareDns($domain),
                DomainStatus::CLOUDFLARE_DNS        => $this->routeAfterDns($domain),
                DomainStatus::STORMWALL_BACKENDS    => $this->addStormWallBackends($domain),
                DomainStatus::STORMWALL_SSL_REQUESTED => $this->requestStormWallSsl($domain),
                DomainStatus::WAITING_STORMWALL_SSL => $this->waitForStormWallSsl($domain),
                default => null,
            };
        } catch (Throwable $e) {
            Log::channel('domain')->error('Workflow step FAILED', [
                'domain_id' => $domain->id,
                'domain'    => $domain->domain,
                'step'      => $status->value,
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $this->fail($domain, $status, $e);
        }
    }

    private function createCloudflareZone(Domain $domain): void
    {
        $zone = $this->cf->createZone($domain->domain);

        $domain->update([
            'cloudflare_zone_id'       => $zone->id,
            'cloudflare_nameservers'   => $zone->nameservers ?: null,
            'status'                   => DomainStatus::CLOUDFLARE_ZONE->value,
        ]);

        $this->cf->applyZoneSettings($zone->id);

        $this->logger->success($domain, DomainStatus::INIT->value, [
            'domain' => $domain->domain,
        ], [
            'cloudflare_zone_id' => $zone->id,
            'status'             => $zone->status,
        ]);

        $this->dispatchNextStep($domain);
    }

    // After zone: for dns mode → create SW domain first to get its IP
    //             for cf mode  → go straight to DNS record
    private function routeAfterZone(Domain $domain): void
    {
        if ($domain->mode === 'dns') {
            $this->createStormWallDomain($domain);
        } else {
            $this->createOrUpdateCloudflareDns($domain);
        }
    }

    private function createStormWallDomain(Domain $domain): void
    {
        $swDomain = $this->sw->createStormWallDomain(
            new \App\Services\StormWall\DTO\CreateDomainData($domain->domain)
        );

        $updates = [
            'stormwall_domain_id' => $swDomain->id,
            'status'              => DomainStatus::STORMWALL_DOMAIN->value,
        ];

        // Persist the StormWall-assigned IP so next step can use it for CF DNS
        if ($swDomain->ip) {
            $updates['stormwall_ip'] = $swDomain->ip;
        }

        $domain->update($updates);

        $this->logger->success($domain, DomainStatus::CLOUDFLARE_ZONE->value, [
            'domain' => $domain->domain,
        ], [
            'stormwall_domain_id' => $swDomain->id,
            'stormwall_ip'        => $swDomain->ip,
        ]);

        $this->dispatchNextStep($domain);
    }

    private function createOrUpdateCloudflareDns(Domain $domain): void
    {
        $zoneId   = $this->requireValue($domain->cloudflare_zone_id, 'cloudflare_zone_id');
        $targetIp = $this->cloudflareTargetIp($domain);
        $proxied  = $domain->mode === 'cf';

        $existing = $this->cf->findDnsRecord($zoneId, $domain->domain);

        $record = $existing
            ? $this->cf->updateDnsRecord($zoneId, $existing->id, $targetIp, $proxied)
            : $this->cf->createDnsRecord($zoneId, $domain->domain, $targetIp, $proxied);

        $domain->update([
            'cloudflare_dns_id' => $record->id,
            'status'            => DomainStatus::CLOUDFLARE_DNS->value,
        ]);

        $this->logger->success($domain, DomainStatus::STORMWALL_DOMAIN->value . '|' . DomainStatus::CLOUDFLARE_ZONE->value, [
            'zone_id'   => $zoneId,
            'name'      => $domain->domain,
            'target_ip' => $targetIp,
            'proxied'   => $proxied,
        ], [
            'cloudflare_dns_id' => $record->id,
            'content'           => $record->content,
            'proxied'           => $record->proxied,
        ]);

        $this->dispatchNextStep($domain);
    }

    // After DNS: cf → DONE, dns → add SW backends
    private function routeAfterDns(Domain $domain): void
    {
        if ($domain->mode === 'cf') {
            $domain->update(['status' => DomainStatus::DONE->value]);

            $this->logger->success($domain, DomainStatus::CLOUDFLARE_DNS->value, [
                'mode' => $domain->mode,
            ], [
                'next_status' => DomainStatus::DONE->value,
            ]);

            return;
        }

        $domain->update(['status' => DomainStatus::STORMWALL_BACKENDS->value]);

        $this->logger->success($domain, DomainStatus::CLOUDFLARE_DNS->value, [
            'mode' => $domain->mode,
        ], [
            'next_status' => DomainStatus::STORMWALL_BACKENDS->value,
        ]);

        $this->dispatchNextStep($domain);
    }

    private function addStormWallBackends(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue((string) $domain->stormwall_domain_id, 'stormwall_domain_id');
        $serverIp          = $this->requireValue($domain->server_ip, 'server_ip');

        $this->sw->addBackends($stormWallDomainId, $serverIp);

        $nextStatus = $this->stormWallSslEnabled()
            ? DomainStatus::STORMWALL_SSL_REQUESTED
            : DomainStatus::DONE;

        $domain->update(['status' => $nextStatus->value]);

        $this->logger->success($domain, DomainStatus::STORMWALL_BACKENDS->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'server_ip'           => $serverIp,
        ], [
            'next_status' => $nextStatus->value,
        ]);

        if ($nextStatus === DomainStatus::STORMWALL_SSL_REQUESTED) {
            $this->dispatchNextStep($domain, $this->nextStormWallSslAttemptAt());
            return;
        }

        $this->dispatchNextStep($domain);
    }

    private function requestStormWallSsl(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue($domain->stormwall_domain_id, 'stormwall_domain_id');

        $this->sw->requestLetsEncryptSsl($stormWallDomainId, LetsEncryptSslData::fromConfig());

        $nextAttemptAt = $this->nextStormWallSslAttemptAt();

        $domain->update([
            'status' => DomainStatus::WAITING_STORMWALL_SSL->value,
            'ssl_requested_at' => now(),
            'next_attempt_at' => $nextAttemptAt,
        ]);

        $this->logger->success($domain, DomainStatus::STORMWALL_SSL_REQUESTED->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'www_included' => (bool) config('services.stormwall.ssl.www_included'),
        ], [
            'next_status' => DomainStatus::WAITING_STORMWALL_SSL->value,
            'next_attempt_at' => $nextAttemptAt->toISOString(),
        ]);

        $this->dispatchNextStep($domain, $nextAttemptAt);
    }

    private function waitForStormWallSsl(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue($domain->stormwall_domain_id, 'stormwall_domain_id');

        if ($this->sw->isSslReady($stormWallDomainId)) {
            $domain->update([
                'status' => DomainStatus::DONE->value,
                'ssl_ready_at' => now(),
                'next_attempt_at' => null,
            ]);

            $this->logger->success($domain, DomainStatus::WAITING_STORMWALL_SSL->value, [
                'stormwall_domain_id' => $stormWallDomainId,
            ], [
                'ssl_ready' => true,
                'next_status' => DomainStatus::DONE->value,
            ]);

            return;
        }

        if ($this->stormWallSslWaitExpired($domain)) {
            throw new \RuntimeException('StormWall SSL certificate was not ready before the max wait window expired.');
        }

        $nextAttemptAt = $this->nextStormWallSslAttemptAt();

        $domain->update(['next_attempt_at' => $nextAttemptAt]);

        $this->logger->success($domain, DomainStatus::WAITING_STORMWALL_SSL->value, [
            'stormwall_domain_id' => $stormWallDomainId,
        ], [
            'ssl_ready' => false,
            'next_attempt_at' => $nextAttemptAt->toISOString(),
        ]);

        $this->dispatchNextStep($domain, $nextAttemptAt);
    }

    private function fail(Domain $domain, DomainStatus $status, Throwable $e): void
    {
        $domain->update([
            'status' => DomainStatus::FAILED->value,
            'retries' => $domain->retries + 1,
        ]);

        $this->logger->failure($domain, $status->value, response: [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function dispatchNextStep(Domain $domain, ?Carbon $delayUntil = null): void
    {
        $domain->refresh();
        $status = DomainStatus::tryFrom((string) $domain->status);

        if ($status && ! $status->isTerminal()) {
            $job = ProcessDomainJob::dispatch($domain->id);

            if ($delayUntil) {
                $job->delay($delayUntil);
            }
        }
    }

    private function cloudflareTargetIp(Domain $domain): string
    {
        if ($domain->mode === 'cf') {
            return $this->requireValue($domain->server_ip, 'server_ip');
        }

        return $this->requireValue($domain->stormwall_ip, 'stormwall_ip');
    }

    private function requireValue(?string $value, string $field): string
    {
        return $value ?: throw new \InvalidArgumentException("Domain [{$field}] is required for this workflow step.");
    }

    private function stormWallSslEnabled(): bool
    {
        return (bool) config('services.stormwall.ssl.lets_encrypt_enabled');
    }

    private function nextStormWallSslAttemptAt(): Carbon
    {
        return now()->addSeconds((int) config('services.stormwall.ssl.poll_delay_seconds'));
    }

    private function stormWallSslWaitExpired(Domain $domain): bool
    {
        $startedAt = $domain->ssl_requested_at ?? $domain->updated_at;

        return $startedAt
            ->copy()
            ->addMinutes((int) config('services.stormwall.ssl.max_wait_minutes'))
            ->isPast();
    }
}
