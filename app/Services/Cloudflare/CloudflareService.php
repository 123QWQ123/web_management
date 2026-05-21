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

    public function deleteZone(string $zoneId): void
    {
        $this->client->request('delete', "/zones/{$zoneId}");
    }

    public function findZoneByName(string $domain): ?string
    {
        $res = $this->client->request('get', '/zones', ['name' => $domain]);
        return $res['result'][0]['id'] ?? null;
    }

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
}
