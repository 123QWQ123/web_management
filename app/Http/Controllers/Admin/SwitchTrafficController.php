<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainStatus;
use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

class SwitchTrafficController extends Controller
{
    // Modes where Cloudflare handles HTTP traffic (proxied)
    private const CF_MODES = ['cf', 'cf_sw'];

    // Modes that require a provisioned StormWall domain
    private const SW_MODES = ['sw', 'cf_sw'];

    // active_traffic_receiver value per mode
    private const RECEIVER_MAP = [
        'cf'    => 'cf',
        'sw'    => 'sw',
        'cf_sw' => 'cf', // CF is primary receiver in cf_sw
    ];

    public function __construct(
        private CloudflareServiceInterface  $cf,
        private StormWallServiceInterface   $sw,
    ) {}

    /**
     * Switch the domain to a new routing mode.
     * Saves the current mode+config for one-click revert.
     */
    public function switchTraffic(Domain $domain, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:cf,sw,cf_sw'],
        ]);

        $targetMode = $data['mode'];

        if ($domain->status !== 'done') {
            return redirect()->back()->with('error', "Переключение доступно только для доменов со статусом «done».");
        }

        if ($domain->mode === $targetMode) {
            return redirect()->back()->with('error', "Домен уже работает в режиме [{$targetMode}].");
        }

        try {
            // If target mode needs CF but zone doesn't exist yet (e.g. switching from sw),
            // provision CF zone + DNS record now before running precondition checks.
            if (in_array($targetMode, self::CF_MODES) && ! $domain->cloudflare_zone_id) {
                $this->provisionCloudflareZone($domain, $targetMode);
            }

            $this->validateSwitchPreconditions($domain, $targetMode);
            $this->applyCfDnsSwitch($domain, $targetMode);
        } catch (Throwable $e) {
            Log::channel('domain')->error('Traffic switch failed', [
                'domain'      => $domain->domain,
                'from_mode'   => $domain->mode,
                'target_mode' => $targetMode,
                'error'       => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', "Ошибка переключения: {$e->getMessage()}");
        }

        $domain->update([
            'previous_mode'            => $domain->mode,
            'previous_config'          => $this->snapshotConfig($domain),
            'mode'                     => $targetMode,
            'active_traffic_receiver'  => self::RECEIVER_MAP[$targetMode] ?? 'cf',
        ]);

        // If switching to sw/cf_sw mode and SSL was never completed,
        // kick off the SSL workflow so the domain doesn't stay falsely "done".
        if (in_array($targetMode, self::SW_MODES) && ! $domain->ssl_ready_at) {
            $domain->update([
                'status'            => DomainStatus::STORMWALL_SSL_REQUESTED->value,
                'ssl_requested_at'  => now(),
            ]);

            $sslDelay = (int) config('services.stormwall.ssl.poll_delay_seconds', 300);
            ProcessDomainJob::dispatch($domain->id)->delay(now()->addSeconds($sslDelay));

            Log::channel('domain')->info('SSL not yet issued — SSL workflow triggered after switch', [
                'domain'     => $domain->domain,
                'to_mode'    => $targetMode,
                'delay_sec'  => $sslDelay,
            ]);

            return redirect()->route('admin.domains.index')
                ->with('status', "Домен [{$domain->domain}]: режим переключён → [{$targetMode}]. Запущено отримання SSL сертифіката.");
        }

        Log::channel('domain')->info('Traffic switched', [
            'domain'      => $domain->domain,
            'from_mode'   => $domain->previous_mode,
            'to_mode'     => $targetMode,
        ]);

        return redirect()->route('admin.domains.index')
            ->with('status', "Домен [{$domain->domain}]: режим переключён [{$domain->previous_mode}] → [{$targetMode}].");
    }

    /**
     * Revert the domain to the previously saved routing mode.
     */
    public function revertTraffic(Domain $domain): RedirectResponse
    {
        if (! $domain->previous_mode) {
            return redirect()->back()->with('error', "Нет сохранённого состояния для восстановления.");
        }

        $previousMode   = $domain->previous_mode;
        $previousConfig = $domain->previous_config ?? [];

        try {
            $this->applyCfDnsRevert($domain, $previousMode, $previousConfig);
        } catch (Throwable $e) {
            Log::channel('domain')->error('Traffic revert failed', [
                'domain'        => $domain->domain,
                'revert_to_mode' => $previousMode,
                'error'         => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', "Ошибка отката: {$e->getMessage()}");
        }

        $currentMode = $domain->mode;

        $domain->update([
            'mode'                    => $previousMode,
            'previous_mode'           => null,
            'previous_config'         => null,
            'active_traffic_receiver' => self::RECEIVER_MAP[$previousMode] ?? 'cf',
            // Restore stormwall_ip / server_ip if they were preserved in snapshot
            'stormwall_ip'            => $previousConfig['stormwall_ip'] ?? $domain->stormwall_ip,
            'server_ip'               => $previousConfig['server_ip']    ?? $domain->server_ip,
        ]);

        Log::channel('domain')->info('Traffic reverted', [
            'domain'    => $domain->domain,
            'from_mode' => $currentMode,
            'to_mode'   => $previousMode,
        ]);

        return redirect()->route('admin.domains.index')
            ->with('status', "Домен [{$domain->domain}]: режим восстановлен [{$currentMode}] → [{$previousMode}].");
    }

    // ─── CF zone provisioning on-the-fly ────────────────────────────────────

    /**
     * Create CF zone + DNS record for a domain that has none (e.g. was in sw mode).
     * After this method the domain will have cloudflare_zone_id, cloudflare_dns_id,
     * cloudflare_nameservers set in the database.
     */
    private function provisionCloudflareZone(Domain $domain, string $targetMode): void
    {
        // Check if zone already exists in CF (idempotent)
        $existingZoneId = $this->cf->findZoneByName($domain->domain);

        if ($existingZoneId) {
            $domain->cloudflare_zone_id = $existingZoneId;
            // Refresh zone status when reusing existing zone
            $domain->cloudflare_zone_status = $this->cf->getZoneStatus($existingZoneId);
        } else {
            $zone = $this->cf->createZone($domain->domain);
            $this->cf->applyZoneSettings($zone->id);
            $domain->cloudflare_zone_id      = $zone->id;
            $domain->cloudflare_zone_status  = $zone->status;
            $domain->cloudflare_nameservers  = $zone->nameservers ?: null;
        }

        $domain->save();

        // Determine DNS target based on target mode
        [$ip, $proxied] = match ($targetMode) {
            'cf_sw' => [$domain->stormwall_ip, false],  // DNS Only — SW is edge
            default => [$domain->server_ip, true],       // cf → proxied to server_ip
        };

        if (! $ip) {
            throw new \RuntimeException(
                $targetMode === 'cf_sw'
                    ? 'Отсутствует stormwall_ip. Невозможно настроить CF → SW режим.'
                    : 'Отсутствует server_ip. Невозможно создать DNS запись Cloudflare.'
            );
        }

        $existing = $this->cf->findDnsRecord($domain->cloudflare_zone_id, $domain->domain);
        $record = $existing
            ? $this->cf->updateDnsRecord($domain->cloudflare_zone_id, $existing->id, $ip, $proxied)
            : $this->cf->createDnsRecord($domain->cloudflare_zone_id, $domain->domain, $ip, $proxied);

        Log::channel('domain')->info('CF zone provisioned on-the-fly during switch', [
            'domain'                 => $domain->domain,
            'cloudflare_zone_id'     => $domain->cloudflare_zone_id,
            'cloudflare_zone_status' => $domain->cloudflare_zone_status,
            'cloudflare_dns_id'      => $domain->cloudflare_dns_id,
            'target_mode'            => $targetMode,
        ]);
    }

    // ─── Precondition checks ─────────────────────────────────────────────────

    private function validateSwitchPreconditions(Domain $domain, string $targetMode): void
    {
        // Any SW-dependent mode requires a provisioned SW domain
        if (in_array($targetMode, self::SW_MODES) && ! $domain->stormwall_domain_id) {
            throw new \RuntimeException('StormWall не настроен для этого домена. Пересоздайте домен в нужном режиме.');
        }

        // cf_sw mode requires known SW proxy IP for CF DNS update
        if ($targetMode === 'cf_sw' && ! $domain->stormwall_ip) {
            throw new \RuntimeException('Отсутствует stormwall_ip. Невозможно обновить DNS Cloudflare.');
        }
    }

    // ─── CF DNS + SW backend changes on switch ───────────────────────────────

    private function applyCfDnsSwitch(Domain $domain, string $targetMode): void
    {
        $this->applySwBackendSwitch($domain, $targetMode);
        $this->applyCfDnsUpdate($domain, $targetMode);
    }

    /**
     * Update StormWall backends when mode transitions require a different backend IP.
     *
     * sw / cf_sw → server_ip is the SW backend (SW sends traffic directly to backend)
     *
     * We always update backends when the target mode defines a specific SW backend IP,
     * regardless of what the previous mode was. This handles multi-hop scenarios correctly.
     */
    private function applySwBackendSwitch(Domain $domain, string $targetMode): void
    {
        if (! $domain->stormwall_domain_id) {
            return; // No SW domain provisioned — nothing to update
        }

        $swId = (int) $domain->stormwall_domain_id;

        if (in_array($targetMode, ['sw', 'cf_sw'])) {
            // Both sw and cf_sw use server_ip as SW backend
            $this->sw->replaceBackends($swId, $domain->server_ip);
        }
        // cf: SW is not in the traffic path — leave backends as-is
    }

    private function applyCfDnsUpdate(Domain $domain, string $targetMode): void
    {
        $zoneId = $domain->cloudflare_zone_id;

        // sw mode has no CF DNS record to update
        if (! $zoneId || $targetMode === 'sw') {
            return;
        }

        [$ip, $proxied] = match ($targetMode) {
            'cf'    => [$domain->server_ip, true],
            'cf_sw' => [$domain->stormwall_ip, false],  // DNS Only — CF resolves to SW IP, traffic direct to SW
            default => [$domain->server_ip, true],
        };

        $dnsId = $domain->cloudflare_dns_id;

        if ($dnsId) {
            $record = $this->cf->updateDnsRecord($zoneId, $dnsId, $ip, $proxied);
        } else {
            // Zone exists but DNS record missing — create it
            $existing = $this->cf->findDnsRecord($zoneId, $domain->domain);
            $record = $existing
                ? $this->cf->updateDnsRecord($zoneId, $existing->id, $ip, $proxied)
                : $this->cf->createDnsRecord($zoneId, $domain->domain, $ip, $proxied);
            $domain->cloudflare_dns_id = $record->id;
            $domain->save();
        }
    }

    private function applyCfDnsRevert(Domain $domain, string $previousMode, array $previousConfig): void
    {
        // Restore SW backends if needed
        if ($domain->stormwall_domain_id) {
            $swId = (int) $domain->stormwall_domain_id;
            if (in_array($previousMode, ['sw', 'cf_sw'])) {
                $restoreServerIp = $previousConfig['server_ip'] ?? $domain->server_ip;
                if ($restoreServerIp) {
                    $this->sw->replaceBackends($swId, $restoreServerIp);
                }
            }
        }

        // Restore CF DNS
        $zoneId = $domain->cloudflare_zone_id;
        $dnsId  = $previousConfig['cloudflare_dns_id'] ?? $domain->cloudflare_dns_id;

        if (! $zoneId || ! $dnsId || $previousMode === 'sw') {
            return;
        }

                [$ip, $proxied] = match ($previousMode) {
            'cf'    => [$previousConfig['server_ip'] ?? $domain->server_ip, true],
            'cf_sw' => [$previousConfig['stormwall_ip'] ?? $domain->stormwall_ip, false],  // DNS Only
            default => [$previousConfig['server_ip'] ?? $domain->server_ip, true],
        };

        $this->cf->updateDnsRecord($zoneId, $dnsId, $ip, $proxied);
    }

    // ─── Snapshot ────────────────────────────────────────────────────────────

    private function snapshotConfig(Domain $domain): array
    {
        return [
            'mode'               => $domain->mode,
            'cloudflare_dns_id'  => $domain->cloudflare_dns_id,
            'stormwall_ip'       => $domain->stormwall_ip,
            'server_ip'          => $domain->server_ip,
        ];
    }

    // ─── Sync CF DNS ─────────────────────────────────────────────────────────

    /**
     * Re-apply the correct CF DNS record for the domain's current mode.
     * Useful when a record was created with wrong settings (e.g. proxied instead of DNS Only).
     */
    public function syncCfDns(Domain $domain): RedirectResponse
    {
        if (! $domain->cloudflare_zone_id) {
            return redirect()->back()->with('error', "У домена [{$domain->domain}] нет CF зоны.");
        }

        [$ip, $proxied] = match ($domain->mode) {
            'cf'    => [$domain->server_ip,    true],
            'cf_sw' => [$domain->stormwall_ip, false],
            'sw'    => [null, false],
            default => [null, false],
        };

        if (! $ip) {
            return redirect()->back()->with('error', "Нет IP для обновления CF DNS (режим: {$domain->mode}).");
        }

        try {
            $zoneId = $domain->cloudflare_zone_id;
            $dnsId  = $domain->cloudflare_dns_id;

            if ($dnsId) {
                $record = $this->cf->updateDnsRecord($zoneId, $dnsId, $ip, $proxied);
            } else {
                $existing = $this->cf->findDnsRecord($zoneId, $domain->domain);
                $record = $existing
                    ? $this->cf->updateDnsRecord($zoneId, $existing->id, $ip, $proxied)
                    : $this->cf->createDnsRecord($zoneId, $domain->domain, $ip, $proxied);
                $domain->update(['cloudflare_dns_id' => $record->id]);
            }

            Log::channel('domain')->info('CF DNS synced manually', [
                'domain'  => $domain->domain,
                'mode'    => $domain->mode,
                'ip'      => $ip,
                'proxied' => $proxied,
                'record'  => $record->id,
            ]);
        } catch (Throwable $e) {
            return redirect()->back()->with('error', "Ошибка синхронизации CF DNS: {$e->getMessage()}");
        }

        $proxiedLabel = $proxied ? 'Proxied' : 'DNS Only';

        return redirect()->route('admin.domains.index')
            ->with('status', "CF DNS синхронизирован: [{$domain->domain}] → {$ip} ({$proxiedLabel}).");
    }
}
