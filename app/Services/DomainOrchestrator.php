<?php

namespace App\Services;

use App\Enums\DomainStatus;
use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use App\Services\StormWall\DTO\LetsEncryptSslData;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the step-by-step domain provisioning workflow.
 *
 * Each call to handle() executes exactly one workflow step based on the domain's current status,
 * then dispatches a new ProcessDomainJob for the next step (fan-forward pattern).
 *
 * Workflow steps per routing mode:
 *   cf:    INIT → CF_ZONE → CF_DNS (proxied) → DONE
 *   sw:    INIT → SW_DOMAIN → SW_BACKENDS → SSL_REQUEST → WAIT_SSL → DONE
 *   cf_sw: INIT → CF_ZONE → SW_DOMAIN → CF_DNS (DNS Only→SW IP) → SW_BACKENDS → SSL_REQUEST → WAIT_SSL → DONE
 *          (NS at CF, CF resolves domain to SW IP, traffic goes directly to SW, SW terminates SSL)
 *
 * All external API calls are delegated to CloudflareService and StormWallService.
 * Every step is logged via DomainWorkflowLogger with full request/response context.
 */
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
                DomainStatus::SW_BACKENDS             => $this->addSwBackends($domain),
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

    // sw skips Cloudflare entirely; all other modes start with a CF zone.
    private function handleInit(Domain $domain): void
    {
        if ($domain->mode === 'sw') {
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
    //   cf_sw → create SW domain first (need SW proxy IP for CF DNS)
    //   cf    → go straight to CF DNS record
    private function routeAfterZone(Domain $domain): void
    {
        if ($domain->mode === 'cf_sw') {
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

        // Fetch NS records so operator knows what to set at the registrar
        try {
            $ns = $this->sw->getNameservers($swDomain->id);
            if (! empty($ns)) {
                $updates['stormwall_nameservers'] = $ns;
            }
        } catch (\Throwable) {
            // Non-critical: NS fetch failure doesn't break provisioning
        }

        $domain->update($updates);

        $this->logger->success($domain, DomainStatus::CLOUDFLARE_ZONE->value . '|' . DomainStatus::INIT->value, [
            'domain' => $domain->domain,
            'mode'   => $domain->mode,
        ], [
            'stormwall_domain_id'  => $swDomain->id,
            'stormwall_ip'         => $swDomain->ip,
            'stormwall_nameservers' => $updates['stormwall_nameservers'] ?? null,
        ]);

        $this->dispatchNextStep($domain);
    }

    // After SW domain is created, routing depends on mode:
    //   cf_sw → create CF DNS record (pointing to SW proxy IP, proxied=true)
    //   sw    → set SW_BACKENDS, dispatch (SW backends use server_ip)
    private function routeAfterSwDomain(Domain $domain): void
    {
        match ($domain->mode) {
            'cf_sw'  => $this->createOrUpdateCloudflareDns($domain),
            'sw'     => $this->scheduleStep($domain, DomainStatus::SW_BACKENDS, DomainStatus::STORMWALL_DOMAIN->value),
            default  => null,
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
        ]);

        $this->dispatchNextStep($domain);
    }

    // After CF DNS, routing depends on mode:
    //   cf    → DONE
    //   cf_sw → STORMWALL_BACKENDS (add SW backends with server_ip)
    private function routeAfterDns(Domain $domain): void
    {
        match ($domain->mode) {
            'cf'    => $this->markDone($domain, DomainStatus::CLOUDFLARE_DNS->value),
            'cf_sw' => $this->scheduleStep($domain, DomainStatus::STORMWALL_BACKENDS, DomainStatus::CLOUDFLARE_DNS->value),
            default => $this->markDone($domain, DomainStatus::CLOUDFLARE_DNS->value),
        };
    }

    // ─── StormWall backends ──────────────────────────────────────────────────

    // cf_sw mode: CF is DNS Only (NS at CF, A→SW IP, not proxied). SW is the edge, needs SSL.
    private function addStormWallBackends(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue((string) $domain->stormwall_domain_id, 'stormwall_domain_id');
        $serverIp          = $this->requireValue($domain->server_ip, 'server_ip');

        $this->sw->addBackends($stormWallDomainId, $serverIp);

        $domain->update(['status' => DomainStatus::STORMWALL_SSL_REQUESTED->value]);

        $this->logger->success($domain, DomainStatus::STORMWALL_BACKENDS->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'server_ip'           => $serverIp,
        ], [
            'next_status' => DomainStatus::STORMWALL_SSL_REQUESTED->value,
        ]);

        $this->dispatchNextStep($domain, $this->nextStormWallSslAttemptAt());
    }

    // sw mode: SW is the only service, backend = server_ip, no CF
    private function addSwBackends(Domain $domain): void
    {
        $stormWallDomainId = (int) $this->requireValue((string) $domain->stormwall_domain_id, 'stormwall_domain_id');
        $serverIp          = $this->requireValue($domain->server_ip, 'server_ip');

        $this->sw->addBackends($stormWallDomainId, $serverIp);

        $domain->update(['status' => DomainStatus::STORMWALL_SSL_REQUESTED->value]);

        $this->logger->success($domain, DomainStatus::SW_BACKENDS->value, [
            'stormwall_domain_id' => $stormWallDomainId,
            'server_ip'           => $serverIp,
        ], [
            'next_status' => DomainStatus::STORMWALL_SSL_REQUESTED->value,
        ]);

        $this->dispatchNextStep($domain, $this->nextStormWallSslAttemptAt());
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
            // SSL is active — enable HTTPS redirect before marking done
            try {
                $this->sw->setHttpsRedirect($stormWallDomainId);
            } catch (\Throwable $e) {
                // Non-critical: redirect config failure doesn't block domain activation
                Log::channel('domain')->warning('Failed to set HTTPS redirect', [
                    'domain'    => $domain->domain,
                    'sw_domain' => $stormWallDomainId,
                    'error'     => $e->getMessage(),
                ]);
            }

            $domain->update([
                'status'          => DomainStatus::DONE->value,
                'ssl_ready_at'    => now(),
                'next_attempt_at' => null,
            ]);

            $this->logger->success($domain, DomainStatus::WAITING_STORMWALL_SSL->value, [
                'stormwall_domain_id' => $stormWallDomainId,
            ], [
                'ssl_ready'   => true,
                'https_redirect' => true,
                'next_status' => DomainStatus::DONE->value,
            ]);

            return;
        }

        if ($this->stormWallSslWaitExpired($domain)) {
            // SSL is taking longer than expected — reschedule another check instead of failing.
            // The operator can see ssl_requested_at in the UI and decide to act manually.
            $nextAttemptAt = $this->nextStormWallSslAttemptAt();

            $domain->update(['next_attempt_at' => $nextAttemptAt]);

            $this->logger->success($domain, DomainStatus::WAITING_STORMWALL_SSL->value, [
                'stormwall_domain_id' => $stormWallDomainId,
            ], [
                'ssl_ready'      => false,
                'note'           => 'max_wait_expired_rescheduled',
                'next_attempt_at' => $nextAttemptAt->toISOString(),
            ]);

            $this->dispatchNextStep($domain, $nextAttemptAt);
            return;
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

    /**
     * Dispatch a job for the next workflow step, optionally with a delay.
     * Re-reads the domain status to avoid dispatching on a terminal state.
     */
    private function dispatchNextStep(Domain $domain, ?CarbonInterface $delayUntil = null): void
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
    //   cf_sw → SW proxy IP (CF proxies to StormWall, then SW routes to server)
    //   cf    → server_ip (CF proxies to backend directly)
    private function cloudflareTargetIp(Domain $domain): string
    {
        if ($domain->mode === 'cf_sw') {
            return $this->requireValue($domain->stormwall_ip, 'stormwall_ip');
        }

        return $this->requireValue($domain->server_ip, 'server_ip');
    }

    // All CF modes use proxied=true (cf_sw is proxied=true, unlike old dns which was false).
    // Note: sw mode has no CF DNS, so this method won't be called for it.
    private function isCloudflareProxied(Domain $domain): bool
    {
        // cf_sw: DNS Only (proxied=false) — CF just resolves domain to SW IP,
        // traffic goes directly to SW. SW is the edge and handles SSL termination.
        // cf: proxied=true — CF acts as proxy in front of the backend.
        return $domain->mode !== 'cf_sw';
    }

    /**
     * Asserts a required string field is not empty.
     * Throws InvalidArgumentException with a clear message if missing.
     */
    private function requireValue(?string $value, string $field): string
    {
        return $value ?: throw new \InvalidArgumentException("Domain [{$field}] is required for this workflow step.");
    }


    private function nextStormWallSslAttemptAt(): CarbonInterface
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
