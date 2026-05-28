<?php

namespace App\Http\Controllers\Admin;

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
    private const CF_MODES = ['cf', 'sw_cf', 'cf_sw'];

    // Modes that require a provisioned StormWall domain
    private const SW_MODES = ['sw', 'sw_cf', 'cf_sw'];

    // active_traffic_receiver value per mode
    private const RECEIVER_MAP = [
        'cf'    => 'cf',
        'sw'    => 'sw',
        'sw_cf' => 'sw',
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
            'mode'        => ['required', 'in:cf,sw,cf_sw,sw_cf'],
            'cf_proxy_ip' => ['nullable', 'ip'],
        ]);

        $targetMode = $data['mode'];

        if ($domain->status !== 'done') {
            return redirect()->back()->with('error', "Переключение доступно только для доменов со статусом «done».");
        }

        if ($domain->mode === $targetMode) {
            return redirect()->back()->with('error', "Домен уже работает в режиме [{$targetMode}].");
        }

        // For sw_cf mode: cf_proxy_ip is required.
        // 1) Use value from form if provided, 2) otherwise auto-resolve via CF nameservers.
        if ($targetMode === 'sw_cf' && ! $domain->cf_proxy_ip) {
            if (! empty($data['cf_proxy_ip'])) {
                $domain->cf_proxy_ip = $data['cf_proxy_ip'];
                $domain->save();
            } elseif (! empty($domain->cloudflare_nameservers) && $domain->cloudflare_zone_id) {
                $resolved = $this->cf->resolveProxiedIp($domain->domain, $domain->cloudflare_nameservers);
                if ($resolved) {
                    $domain->cf_proxy_ip = $resolved;
                    $domain->save();
                    Log::channel('domain')->info('cf_proxy_ip auto-resolved', [
                        'domain'      => $domain->domain,
                        'cf_proxy_ip' => $resolved,
                    ]);
                }
            }
        } elseif ($targetMode === 'sw_cf' && ! empty($data['cf_proxy_ip'])) {
            // Update if a new value was explicitly provided
            $domain->cf_proxy_ip = $data['cf_proxy_ip'];
            $domain->save();
        }

        try {
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

        // sw_cf mode requires cf_proxy_ip to configure SW backends correctly
        if ($targetMode === 'sw_cf' && ! $domain->cf_proxy_ip) {
            throw new \RuntimeException('Отсутствует cf_proxy_ip. Невозможно настроить SW → CF режим.');
        }

        // CF-based modes require a provisioned CF zone
        if (in_array($targetMode, self::CF_MODES) && ! $domain->cloudflare_zone_id) {
            throw new \RuntimeException('Cloudflare зона не настроена для этого домена.');
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
     * sw_cf      → cf_proxy_ip is the SW backend (SW sends traffic to CF proxy)
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

        if ($targetMode === 'sw_cf') {
            // SW must route to CF proxy IP
            $this->sw->replaceBackends($swId, $domain->cf_proxy_ip);
        } elseif (in_array($targetMode, ['sw', 'cf_sw'])) {
            // Both sw and cf_sw use server_ip as SW backend
            $this->sw->replaceBackends($swId, $domain->server_ip);
        }
        // cf: SW is not in the traffic path — leave backends as-is
    }

    private function applyCfDnsUpdate(Domain $domain, string $targetMode): void
    {
        $zoneId = $domain->cloudflare_zone_id;
        $dnsId  = $domain->cloudflare_dns_id;

        // sw mode has no CF DNS record to update
        if (! $zoneId || ! $dnsId || $targetMode === 'sw') {
            return;
        }

        [$ip, $proxied] = match ($targetMode) {
            'cf'    => [$domain->server_ip, true],
            'sw_cf' => [$domain->server_ip, true],
            'cf_sw' => [$domain->stormwall_ip, true],  // CF proxies TO stormwall
            default => [$domain->server_ip, true],
        };

        $this->cf->updateDnsRecord($zoneId, $dnsId, $ip, $proxied);
    }

    private function applyCfDnsRevert(Domain $domain, string $previousMode, array $previousConfig): void
    {
        // Restore SW backends if needed
        if ($domain->stormwall_domain_id) {
            $swId = (int) $domain->stormwall_domain_id;
            if ($previousMode === 'sw_cf') {
                $restoreCfProxyIp = $previousConfig['cf_proxy_ip'] ?? $domain->cf_proxy_ip;
                if ($restoreCfProxyIp) {
                    $this->sw->replaceBackends($swId, $restoreCfProxyIp);
                }
            } elseif (in_array($previousMode, ['sw', 'cf_sw'])) {
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
            'sw_cf' => [$previousConfig['server_ip'] ?? $domain->server_ip, true],
            'cf_sw' => [$previousConfig['stormwall_ip'] ?? $domain->stormwall_ip, true],
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
            'cf_proxy_ip'        => $domain->cf_proxy_ip,
        ];
    }
}
