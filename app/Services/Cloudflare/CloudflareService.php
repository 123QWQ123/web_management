<?php

namespace App\Services\Cloudflare;

use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\Cloudflare\Http\CloudflareClient;
use App\Services\Cloudflare\DTO\ZoneData;
use App\Services\Cloudflare\DTO\DnsRecordData;

class CloudflareService implements CloudflareServiceInterface
{
    public function __construct(
        private CloudflareClient $client
    ) {}

    /**
     * Create a new Cloudflare zone for the given domain name.
     * The zone type is 'full' (CF manages DNS authoritatively).
     * After creation, call applyZoneSettings() to apply required security settings.
     */
    public function createZone(string $domain): ZoneData
    {
        $res = $this->client->request('post', '/zones', [
            'name' => $domain,
            'account' => [
                'id' => config('services.cloudflare.account_id'),
            ],
            'type' => 'full',
        ]);

        return ZoneData::fromArray($res['result']);
    }

    public function applyZoneSettings(string $zoneId): void
    {
        // Apply required settings: Always Use HTTPS and minimum TLS 1.2
        // Note: Cloudflare exposes these as separate setting endpoints; using generic settings endpoint here.
        $this->client->request('patch', "/zones/{$zoneId}/settings/always_use_https", ['value' => 'on']);
        $this->client->request('patch', "/zones/{$zoneId}/settings/min_tls_version", ['value' => '1.2']);
    }

    /**
     * Returns the CF zone status: 'active', 'pending', 'initializing', 'moved', 'deleted', 'deactivated'.
     */
    public function getZoneStatus(string $zoneId): string
    {
        $res = $this->client->request('get', "/zones/{$zoneId}");
        return $res['result']['status'] ?? 'pending';
    }

    /**
     * Create a proxied A record in the given zone.
     * proxied=true means CF terminates the connection and forwards to $ip.
     * proxied=false means CF only resolves DNS (DNS-only, no proxying).
     */
    public function createDnsRecord(
        string $zoneId,
        string $name,
        string $ip,
        bool $proxied
    ): DnsRecordData {
        $res = $this->client->request(
            'post',
            "/zones/{$zoneId}/dns_records",
            [
                'type' => 'A',
                'name' => $name,
                'content' => $ip,
                'ttl' => 120,
                'proxied' => $proxied,
            ]
        );

        return DnsRecordData::fromArray($res['result']);
    }

    /**
     * Update an existing A record's IP and proxied flag (PATCH — partial update).
     */
    public function updateDnsRecord(
        string $zoneId,
        string $recordId,
        string $ip,
        bool $proxied
    ): DnsRecordData {
        $res = $this->client->request(
            'patch',
            "/zones/{$zoneId}/dns_records/{$recordId}",
            [
                'content' => $ip,
                'proxied' => $proxied,
            ]
        );

        return DnsRecordData::fromArray($res['result']);
    }

    /** Permanently remove a CF zone and all its DNS records. */
    public function deleteZone(string $zoneId): void
    {
        $this->client->request('delete', "/zones/{$zoneId}");
    }

    /**
     * Look up an existing CF zone by domain name.
     * Returns the zone ID string if found, null if the domain has no zone in CF.
     */
    public function findZoneByName(string $domain): ?string
    {
        $res = $this->client->request('get', '/zones', ['name' => $domain]);
        return $res['result'][0]['id'] ?? null;
    }

    /**
     * Find an existing A record for $name in the given zone.
     * Returns null if no matching record exists.
     */
    public function findDnsRecord(
        string $zoneId,
        string $name
    ): ?DnsRecordData {
        $res = $this->client->request(
            'get',
            "/zones/{$zoneId}/dns_records",
            ['name' => $name]
        );

        return isset($res['result'][0])
            ? DnsRecordData::fromArray($res['result'][0])
            : null;
    }

    /**
     * Resolve the CF anycast proxy IP by querying CF's own nameservers directly.
     *
     * This works ONLY when the CF zone is ACTIVE (registrar NS have been updated).
     * For a pending zone, CF's NS returns the origin IP — not a CF anycast IP —
     * because CF does not proxy traffic for inactive zones. The isCloudflareIp()
     * check will reject the origin IP and this method returns null.
     *
     * @param  string[]  $nameservers  CF zone nameservers (e.g. ['vera.ns.cloudflare.com'])
     */
    public function resolveProxiedIp(string $domain, array $nameservers): ?string
    {
        $escapedDomain = escapeshellarg(trim($domain));
        foreach ($nameservers as $ns) {
            $escapedNs = escapeshellarg(trim($ns));
            // Query CF's own NS directly; +short returns just the IP(s)
            $output = shell_exec("dig +short +time=3 +tries=1 @{$escapedNs} {$escapedDomain} A 2>/dev/null");

            if ($output) {
                $ips = array_filter(array_map('trim', explode("\n", $output)));
                // dig may return a CNAME chain before the final A record — take the last line
                $ip = end($ips);
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && self::isCloudflareIp($ip)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Returns true if the given IP belongs to a known Cloudflare anycast range.
     * Source: https://www.cloudflare.com/ips-v4/
     * Used to avoid accepting backend/origin IPs as the CF proxy IP.
     */
    private static function isCloudflareIp(string $ip): bool
    {
        static $ranges = [
            ['103.21.244.0', 22], ['103.22.200.0', 22], ['103.31.4.0', 22],
            ['104.16.0.0', 13],   ['104.24.0.0', 14],   ['108.162.192.0', 18],
            ['131.0.72.0', 22],   ['141.101.64.0', 18], ['162.158.0.0', 15],
            ['172.64.0.0', 13],   ['173.245.48.0', 20], ['188.114.96.0', 20],
            ['190.93.240.0', 20], ['197.234.240.0', 22], ['198.41.128.0', 17],
        ];

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        foreach ($ranges as [$base, $bits]) {
            $mask = -1 << (32 - $bits);
            if ((ip2long($base) & $mask) === ($long & $mask)) {
                return true;
            }
        }

        return false;
    }
}
