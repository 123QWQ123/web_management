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
            'mode'      => $domain->mode,
        ]);

        try {
            match ($status) {
                DomainStatus::INIT                    => $this->handleInit($domain),
                DomainStatus::CLOUDFLARE_ZONE         => $this->routeAfterZone($domain),
                DomainStatus::STORMWALL_DOMAIN        => $this->routeAfterSwDomain($domain),
                DomainStatus::CLOUDFLARE_DNS          => $this->routeAfterDns($domain),
                DomainStatus::STORMWALL_BACKENDS      => $this->addStormWallBackends($domain),
                DomainStatus::SW_CF_BACKENDS          => $this->addSwCfBackends($domain),
                DomainStatus::SW_ONLY_BACKENDS        => $this->addSwOnlyBackends($domain),
                DomainStatus::STORMWALL_SSL_REQUESTED => $this->requestStormWallSsl($domain),
                DomainStatus::WAITING_STORMWALL_SSL   => $this->waitForStormWallSsl($domain),
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

    // ─── Init ────────────────────────────────────────────────────────────────

    // sw_only skips Cloudflare entirely; all other modes start with a CF zone.
    private function handleInit(Domain $domain): void
    {
        if ($domain->mode === 'sw_only') {
            $this->createStormWallDomain($domain);
        } else {
            $this->createCloudflareZone($domain);
        }
    }

    // ─── Cloudflare zone ─────────────────────────────────────────────────────

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

    // After CF zone:
    //   dns  → create SW domain first (need SW proxy IP for CF DNS)
    //   others (cf, cf_only, sw_cf) → go straight to CF DNS record
    private function routeAfterZone(Domain $domain): void
    {
        if ($domain->mode === 'dns') {
            $this->createStormWallDomain($domain);
        } else {
            $this->createOrUpdateCloudflareDns($domain);
        }
    }

    // ─── StormWall domain ────────────────────────────────────────────────────

    private function createStormWallDomain(Domain $domain): void
    {
        $swDomain = $this->sw->createStormWallDomain(
            new \App\Services\StormWall\DTO\CreateDomainData($domain->domain)
        );

        $updates = [
            'stormwall_domain_id' => $swDomain->id,
            'status'              => DomainStatus::STORMWALL_DOMAIN->value,
        ];

        if ($swDomain->ip) {
            $updates['stormwall_ip'] = $swDomain->ip;
        }

        $domain->update($updates);

        $this->logger->success($domain, DomainStatus::CLOUDFLARE_ZONE->value . '|' . DomainStatus::INIT->value, [
            'domain' => $domain->domain,
            'mode'   => $domain->mode,
        ], [
            'stormwall_domain_id' => $swDomain->id,
            'stormwall_ip'        => $swDomain->ip,
        ]);

        $this->dispatchNextStep($domain);
    }

    // After SW domain is created, routing depends on mode:
    //   dns     → create CF DNS record (pointing to SW proxy IP)
    //   sw_cf   → set SW_CF_BACKENDS, dispatch (SW backends use CF proxy IP)
    //   sw_only → set SW_ONLY_BACKENDS, dispatch (SW backends use server_ip)
    private function routeAfterSwDomain(Domain $domain): void
    {
        match ($domain->mode) {
            'dns'     => $this->createOrUpdateCloudflareDns($domain),
            'sw_cf'   => $this->scheduleStep($domain, DomainStatus::SW_CF_BACKENDS, DomainStatus::STORMWALL_DOMAIN->value),
            'sw_only' => $this->scheduleStep($domain, DomainStatus::SW_ONLY_BACKENDS, DomainStatus::STORMWALL_DOMAIN->value),
            default   => null,
        };
    }

    // ─── Cloudflare DNS ──────────────────────────────────────────────────────

    private function createOrUpdateCloudflareDns(Domain $domain): void
    {
        $zoneId   = $this->requireValue($domain->cloudflare_zone_id, 'cloudflare_zone_id');
        $targetIp = $this->cloudflareTargetIp($domain);
        $proxied  = $this->isCloudflareProxied($domain);

        $existing = $this->cf->findDnsRecord($zoneId, $domain->domain);

        $record = $existing
            ? $this->cf->updateDnsRecord($zoneId, $existing->id, $targetIp, $proxied)
            : $this->cf->createDnsRecord($zoneId, $domain->domain, $targetIp, $proxied);

        $updates = [
            'cloudflare_dns_id' => $record->id,
            'status'            => DomainStatus::CLOUDFLARE_DNS->value,
        ];

        // For sw_cf mode we need the CF anycast proxy IP (what SW will use as backend).
        // Resolve it now by querying CF's own nameservers — works before registrar NS change.
        if ($domain->mode === 'sw_cf' && $proxied && ! $domain->cf_proxy_ip) {
            $nameservers = $domain->cloudflare_nameservers ?? [];
            if (! empty($nameservers)) {
                $proxyIp = $this->cf->resolveProxiedIp($domain->domain, $nameservers);
                if ($proxyIp) {
                    $updates['cf_proxy_ip'] = $proxyIp;
                }
            }
        }

        $domain->update($updates);

        $this->logger->success($domain, DomainStatus::STORMWALL_DOMAIN->value . '|' . DomainStatus::CLOUDFLARE_ZONE->value, [
            'zone_id'    => $zoneId,
            'name'       => $domain->domain,
            'target_ip'  => $targetIp,
            'proxied'    => $proxied,
        ], [
            'cloudflare_dns_id' => $record->id,
            'content'           => $record->content,
            'proxied'           => $record->proxied,
            'cf_proxy_ip'       => $updates['cf_proxy_ip'] ?? null,
        ]);

        $this->dispatchNextStep($domain);
    }

    // After CF DNS, routing depends on mode:
    //   cf / cf_only → DONE
    //   dns          → STORMWALL_BACKENDS (add SW backends with server_ip)
    //   sw_cf        → create SW domain (SW backends will point to CF proxy IP)
    private function routeAfterDns(Domain $domain): void
    {
        match ($domain->mode) {
            'cf', 'cf_only' => $this->markDone($domain, DomainStatus::CLOUDFLARE_DNS->value),
            'dns'           => $this->scheduleStep($domain, DomainStatus::STORMWALL_BACKENDS, DomainStatus::CLOUDFLARE_DNS->value),
            'sw_cf'         => $this->createStormWallDomain($domain),
            default         => $this->markDone($domain, DomainStatus::CLOUDFLARE_DNS->value),
        };
    }

    // ─── StormWall backends ──────────────────────────────────────────────────

    // dns mode: SW backends receive traffic from CF DNS, route to server_ip
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

    // sw_cf mode: SW is primary entry point, backend = CF proxy IP, CF proxies to server_ip
    private function addSwCfBackends(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue((string) $domain->stormwall_domain_id, 'stormwall_domain_id');
        $cfProxyIp         = $this->requireValue($domain->cf_proxy_ip, 'cf_proxy_ip');

        $this->sw->addBackends($stormWallDomainId, $cfProxyIp);

        $domain->update(['status' => DomainStatus::DONE->value]);

        $this->logger->success($domain, DomainStatus::SW_CF_BACKENDS->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'cf_proxy_ip'         => $cfProxyIp,
        ], [
            'next_status' => DomainStatus::DONE->value,
        ]);
    }

    // sw_only mode: SW is the only service, backend = server_ip, no CF
    private function addSwOnlyBackends(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue((string) $domain->stormwall_domain_id, 'stormwall_domain_id');
        $serverIp          = $this->requireValue($domain->server_ip, 'server_ip');

        $this->sw->addBackends($stormWallDomainId, $serverIp);

        $domain->update(['status' => DomainStatus::DONE->value]);

        $this->logger->success($domain, DomainStatus::SW_ONLY_BACKENDS->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'server_ip'           => $serverIp,
        ], [
            'next_status' => DomainStatus::DONE->value,
        ]);
    }

    // ─── StormWall SSL ───────────────────────────────────────────────────────

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

    // ─── Helpers ─────────────────────────────────────────────────────────────

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

    // Advance status and dispatch the next job in one call.
    private function scheduleStep(Domain $domain, DomainStatus $nextStatus, string $logPrevStep): void
    {
        $domain->update(['status' => $nextStatus->value]);

        $this->logger->success($domain, $logPrevStep, [
            'mode' => $domain->mode,
        ], [
            'next_status' => $nextStatus->value,
        ]);

        $this->dispatchNextStep($domain);
    }

    private function markDone(Domain $domain, string $logPrevStep): void
    {
        $domain->update(['status' => DomainStatus::DONE->value]);

        $this->logger->success($domain, $logPrevStep, [
            'mode' => $domain->mode,
        ], [
            'next_status' => DomainStatus::DONE->value,
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

    // CF DNS target IP depends on mode:
    //   dns         → SW proxy IP (CF is DNS-only, traffic flows to SW)
    //   cf/cf_only/sw_cf → server_ip (CF proxies to backend directly)
    private function cloudflareTargetIp(Domain $domain): string
    {
        if ($domain->mode === 'dns') {
            return $this->requireValue($domain->stormwall_ip, 'stormwall_ip');
        }

        return $this->requireValue($domain->server_ip, 'server_ip');
    }

    // CF DNS proxied flag:
    //   dns   → false (CF is DNS-only, passes through to SW)
    //   others → true (CF proxies traffic)
    private function isCloudflareProxied(Domain $domain): bool
    {
        return $domain->mode !== 'dns';
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

