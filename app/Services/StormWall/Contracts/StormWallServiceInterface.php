<?php

namespace App\Services\StormWall\Contracts;

use App\Models\Domain;
use App\Services\StormWall\DTO\BackendData;
use App\Services\StormWall\DTO\CreateDomainData;
use App\Services\StormWall\DTO\LetsEncryptSslData;
use App\Services\StormWall\DTO\ProtectedIpsData;
use App\Services\StormWall\DTO\ProxiedPortData;
use App\Services\StormWall\DTO\SslCertificateData;
use App\Services\StormWall\DTO\StormWallDomainData;

interface StormWallServiceInterface
{
    public function setup(Domain $domain): StormWallDomainData;

    public function createStormWallDomain(CreateDomainData $data): StormWallDomainData;

    public function getDomain(int $domainId): ?StormWallDomainData;

    public function getProxyIp(int $domainId): ?string;

    public function addBackends(int $domainId, string $serverIp): void;

    /** Returns list of backend records: [['id' => int, 'ip' => string], ...] */
    public function listBackends(int $domainId): array;

    public function deleteBackend(int $domainId, int $backendId): void;

    /** Deletes all existing backends and adds one with the new IP. */
    public function replaceBackends(int $domainId, string $newIp): void;

    public function createDomain(CreateDomainData $data): StormWallDomainData;

    public function deleteDomain(int $domainId): void;

    public function findDomain(string $name): ?StormWallDomainData;

    public function assignProtectedIps(int $domainId, ProtectedIpsData $data): void;

    public function addBackend(int $domainId, BackendData $data): void;

    public function requestLetsEncryptSsl(int $domainId, LetsEncryptSslData $data): void;

    public function getSslCertificate(int $domainId): ?SslCertificateData;

    public function isSslReady(int $domainId): bool;

    /**
     * @param  array<int, ProxiedPortData>  $domainPorts
     */
    public function syncProxiedPorts(int $domainId, array $domainPorts): void;
}
