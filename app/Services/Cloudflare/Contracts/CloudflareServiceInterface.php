<?php

namespace App\Services\Cloudflare\Contracts;

use App\Services\Cloudflare\DTO\ZoneData;

use App\Services\Cloudflare\DTO\DnsRecordData;

interface CloudflareServiceInterface

{

    public function createZone(string $domain): ZoneData;

    public function createDnsRecord(

        string $zoneId,

        string $name,

        string $ip,

        bool $proxied

    ): DnsRecordData;

    public function updateDnsRecord(

        string $zoneId,

        string $recordId,

        string $ip,

        bool $proxied

    ): DnsRecordData;

    public function deleteZone(string $zoneId): void;

    public function findZoneByName(string $domain): ?string; // повертає zone ID або null

    public function findDnsRecord(

        string $zoneId,

        string $name

    ): ?DnsRecordData;

    /**
     * Resolve the Cloudflare anycast proxy IP assigned to a proxied domain.
     * Queries CF's own nameservers directly — no registrar NS change required.
     * Returns null if the record is not yet proxied or nameservers are unreachable.
     *
     * @param  string[]  $nameservers  From ZoneData::$nameservers (e.g. ['vera.ns.cloudflare.com'])
     */
    public function resolveProxiedIp(string $domain, array $nameservers): ?string;

}
