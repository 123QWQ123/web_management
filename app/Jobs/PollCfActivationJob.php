<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls Cloudflare zone status until it becomes 'active', then completes
 * the pending mode switch (e.g. sw → sw_cf requires an active CF zone
 * so we can resolve the real CF anycast IP via dig).
 *
 * Dispatched automatically when switching to sw_cf while the CF zone is pending.
 * Queue worker must be running: php artisan queue:work
 */
class PollCfActivationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum number of polling attempts (30s each = ~24 hours) */
    public int $tries = 2880;

    /** No automatic retry backoff — we control it manually with release() */
    public int $backoff = 0;

    public function __construct(public int $domainId) {}

    public function handle(
        CloudflareServiceInterface $cf,
        StormWallServiceInterface  $sw,
    ): void {
        $domain = Domain::find($this->domainId);

        // Domain deleted or no longer waiting
        if (! $domain || ! $domain->pending_mode) {
            Log::channel('domain')->info('PollCfActivation: domain gone or no pending_mode, stopping', [
                'domain_id' => $this->domainId,
            ]);
            return;
        }

        $zoneId = $domain->cloudflare_zone_id;

        if (! $zoneId) {
            Log::channel('domain')->error('PollCfActivation: no cloudflare_zone_id, cannot poll', [
                'domain' => $domain->domain,
            ]);
            $domain->update(['pending_mode' => null]);
            return;
        }

        $status = $cf->getZoneStatus($zoneId);

        Log::channel('domain')->info('PollCfActivation: zone status check', [
            'domain'      => $domain->domain,
            'zone_status' => $status,
            'pending_mode' => $domain->pending_mode,
            'attempt'     => $this->attempts(),
        ]);

        if ($status !== 'active') {
            // Not active yet — retry in 30 seconds
            $this->release(30);
            return;
        }

        // Zone is now active — resolve CF anycast IP
        $nameservers = $domain->cloudflare_nameservers ?? [];
        $cfProxyIp   = $cf->resolveProxiedIp($domain->domain, $nameservers);

        if (! $cfProxyIp) {
            // NS propagated but dig still returned non-CF IP — retry in 30s
            Log::channel('domain')->warning('PollCfActivation: zone active but anycast IP not resolved yet', [
                'domain' => $domain->domain,
            ]);
            $this->release(30);
            return;
        }

        // Save cf_proxy_ip
        $domain->cf_proxy_ip = $cfProxyIp;
        $domain->save();

        Log::channel('domain')->info('PollCfActivation: CF zone active, cf_proxy_ip resolved', [
            'domain'      => $domain->domain,
            'cf_proxy_ip' => $cfProxyIp,
        ]);

        // Complete the pending mode switch: update SW backends → CF anycast IP
        $swId = (int) $domain->stormwall_domain_id;
        if ($swId === 0) {
            Log::channel('domain')->error('PollCfActivation: missing stormwall_domain_id, cannot update backends', [
                'domain' => $domain->domain,
            ]);
            $domain->update(['pending_mode' => null]);
            return;
        }
        $sw->replaceBackends($swId, $cfProxyIp);

        // Finalize mode transition
        $pendingMode = $domain->pending_mode;
        $domain->update([
            'mode'                   => $pendingMode,
            'pending_mode'           => null,
            'active_traffic_receiver' => 'sw',   // sw_cf: SW is the primary receiver
        ]);

        Log::channel('domain')->info('PollCfActivation: mode switch completed', [
            'domain'   => $domain->domain,
            'new_mode' => $pendingMode,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('domain')->error('PollCfActivation: job failed', [
            'domain_id' => $this->domainId,
            'error'     => $e->getMessage(),
        ]);

        // Clear pending_mode so UI shows the real state and operator can retry
        $domain = Domain::find($this->domainId);
        if ($domain) {
            $domain->update(['pending_mode' => null]);
        }
    }
}
