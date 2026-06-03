<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\StoreDomainRequest;
use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use App\Models\Setting;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use Illuminate\Routing\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DomainController extends Controller
{
    public function __construct(
        private CloudflareServiceInterface $cf,
        private StormWallServiceInterface $sw,
    ) {}

    public function index()
    {
        $domains    = Domain::latest()->get();
        $cfProxySetting = Setting::where('key', 'cloudflare_proxy_ips')->first();
        $cfProxyIps     = $cfProxySetting ? $cfProxySetting->value : [];
        return view('admin.domains.index', compact('domains', 'cfProxyIps'));
    }

    public function apiIndex()
    {
        return response()->json(
            Domain::latest()->get()->map(fn (Domain $d) => [
                'id'                      => $d->id,
                'domain'                  => $d->domain,
                'mode'                    => $d->mode,
                'previous_mode'           => $d->previous_mode,
                'active_traffic_receiver' => $d->active_traffic_receiver,
                'status'                  => $d->status,
                'cloudflare_zone_id'      => $d->cloudflare_zone_id,
                'cloudflare_nameservers'  => $d->cloudflare_nameservers ?? [],
                'stormwall_nameservers'   => $d->stormwall_nameservers ?? [],
                'stormwall_ip'            => $d->stormwall_ip,
                'server_ip'               => $d->server_ip,
                'ssl_requested_at'        => $d->ssl_requested_at?->toIso8601String(),
                'ssl_ready_at'            => $d->ssl_ready_at?->toIso8601String(),
                'created_at'              => $d->created_at->format('d.m.Y H:i'),
            ])
        );
    }

    public function create()
    {
        $serverSetting  = Setting::where('key', 'server_ips')->first();
        $cfProxySetting = Setting::where('key', 'cloudflare_proxy_ips')->first();
        $serverIps      = $serverSetting  ? $serverSetting->value  : [];
        $cfProxyIps     = $cfProxySetting ? $cfProxySetting->value : [];

        return view('admin.domains.create', compact('serverIps', 'cfProxyIps'));
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->rememberIp('server_ips',   $data['server_ip'] ?? null);

        // Set active_traffic_receiver based on mode
        $data['active_traffic_receiver'] = $data['mode'] === 'sw' ? 'sw' : 'cf';

        $domain = Domain::create($data);

        ProcessDomainJob::dispatch($domain->id);

        return redirect()->route('admin.domains.index')->with('status', 'Domain created and queued.');
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        // Визначаємо zone_id: з БД або шукаємо по імені домену в CF
        $zoneId = $domain->cloudflare_zone_id
            ?? $this->cf->findZoneByName($domain->domain);

        if ($zoneId) {
            try {
                $this->cf->deleteZone($zoneId);
            } catch (Throwable $e) {
                Log::channel('domain')->warning('CF zone delete failed', [
                    'domain'  => $domain->domain,
                    'zone_id' => $zoneId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($domain->stormwall_domain_id) {
            try {
                $this->sw->deleteDomain((int) $domain->stormwall_domain_id);
            } catch (Throwable $e) {
                Log::channel('domain')->warning('SW domain delete failed', [
                    'domain'    => $domain->domain,
                    'sw_domain' => $domain->stormwall_domain_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $domain->delete();

        return redirect()->route('admin.domains.index')->with('status', "Domain [{$domain->domain}] deleted from all services.");
    }

    /**
     * Reset a failed/stuck domain and re-dispatch its workflow job.
     * For SSL-waiting statuses, resets to stormwall_ssl_requested.
     * For other failed statuses, resets to the current status so the step re-runs.
     */
    public function retry(Domain $domain): RedirectResponse
    {
        $sslStatuses = ['stormwall_ssl_requested', 'waiting_stormwall_ssl', 'failed'];

        $newStatus = match (true) {
            in_array($domain->status, $sslStatuses) && $domain->ssl_requested_at && ! $domain->ssl_ready_at
                => 'stormwall_ssl_requested',
            default
                => $domain->status === 'failed' ? 'init' : $domain->status,
        };

        $domain->update([
            'status'         => $newStatus,
            'retries'        => 0,
            'next_attempt_at' => null,
        ]);

        ProcessDomainJob::dispatch($domain->id)->delay(now()->addSeconds(5));

        Log::channel('domain')->info('Domain workflow manually retried', [
            'domain'     => $domain->domain,
            'new_status' => $newStatus,
        ]);

        return redirect()->route('admin.domains.index')
            ->with('status', "Домен [{$domain->domain}]: воркфлоу перезапущен (статус: {$newStatus}).");
    }

    private function rememberIp(string $key, ?string $ip): void
    {
        if (! $ip) {
            return;
        }

        $setting = Setting::firstOrCreate(['key' => $key], ['value' => []]);
        $current = $setting->value ?? [];

        if (! in_array($ip, $current, true)) {
            $setting->update(['value' => array_values(array_merge($current, [$ip]))]);
        }
    }
}
