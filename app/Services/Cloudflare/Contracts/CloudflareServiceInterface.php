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

}
