<?php

namespace App\Services\StormWall;

use App\Models\Domain;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use App\Services\StormWall\DTO\BackendData;
use App\Services\StormWall\DTO\CreateDomainData;
use App\Services\StormWall\DTO\LetsEncryptSslData;
use App\Services\StormWall\DTO\ProtectedIpsData;
use App\Services\StormWall\DTO\ProxiedPortData;
use App\Services\StormWall\DTO\SslCertificateData;
use App\Services\StormWall\DTO\StormWallDomainData;
use App\Services\StormWall\Exceptions\StormWallException;
use App\Services\StormWall\Http\StormWallClient;

class StormWallService implements StormWallServiceInterface
{
    public function __construct(
        private StormWallClient $client
    ) {}

    public function setup(Domain $domain): StormWallDomainData
    {
        $stormWallDomain = $domain->stormwall_domain_id
            ? new StormWallDomainData((int) $domain->stormwall_domain_id, $domain->domain)
            : $this->createDomain(new CreateDomainData($domain->domain));

        $domain->update(['stormwall_domain_id' => $stormWallDomain->id]);

        if ($domain->stormwall_ip) {
            $this->assignProtectedIps($stormWallDomain->id, ProtectedIpsData::fromIp($domain->stormwall_ip));
        }

        if ($domain->server_ip) {
            $this->addBackend($stormWallDomain->id, BackendData::fromConfig($domain->server_ip));
        }

        return $stormWallDomain;
    }

    public function createStormWallDomain(CreateDomainData $data): StormWallDomainData
    {
        $domain = $this->createDomain($data);
        $ip     = $this->getProxyIp($domain->id);

        return new StormWallDomainData($domain->id, $domain->name, $ip);
    }

    public function getProxyIp(int $domainId): ?string
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}/ips");
        $ips      = data_get($response, 'payload', []);

        if (empty($ips)) {
            return null;
        }

        // Prefer dedicated IP (isShared = false) over shared
        foreach ($ips as $ipData) {
            if (! ($ipData['isShared'] ?? true)) {
                return $ipData['ip'] ?? null;
            }
        }

        return $ips[0]['ip'] ?? null;
    }

    public function addBackends(int $domainId, string $serverIp): void
    {
        $this->addBackend($domainId, BackendData::fromConfig($serverIp));
    }

    public function getDomain(int $domainId): ?StormWallDomainData
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}");
        $payload  = data_get($response, 'payload');

        return is_array($payload) ? StormWallDomainData::fromArray($payload) : null;
    }

    public function createDomain(CreateDomainData $data): StormWallDomainData
    {
        $serviceId = $this->serviceId();

        $response = $this->client->request('post', "/v3/domains?serviceId={$serviceId}", $data->toArray());

        $payload = data_get($response, 'payload')
            ?? data_get($response, 'payload.domain')
            ?? $response;

        if (is_array($payload) && isset($payload['id'])) {
            return StormWallDomainData::fromArray($payload + ['domain' => $data->name]);
        }

        return $this->findDomain($data->name)
            ?? throw new StormWallException("StormWall domain [{$data->name}] was created, but its id was not returned.");
    }

    public function deleteDomain(int $domainId): void
    {
        $this->client->request('delete', "/v3/domains/{$domainId}");
    }

    public function findDomain(string $name): ?StormWallDomainData
    {
        $response = $this->client->request('get', '/v3/domains', [
            'serviceId' => $this->serviceId(),
            'search' => $name,
            'limit' => 100,
            'offset' => 0,
        ]);

        $domains = data_get($response, 'payload.results', []);

        foreach ($domains as $domain) {
            if (($domain['domain'] ?? null) === $name && isset($domain['id'])) {
                return StormWallDomainData::fromArray($domain);
            }
        }

        return null;
    }

    public function assignProtectedIps(int $domainId, ProtectedIpsData $data): void
    {
        $this->client->request('post', "/v3/domains/{$domainId}/protected-ips", $data->toArray());
    }

    public function addBackend(int $domainId, BackendData $data): void
    {
        $serviceId = $this->serviceId();
        $this->client->request('post', "/v3/domains/{$domainId}/backends?serviceId={$serviceId}", $data->toArray());
    }

    public function requestLetsEncryptSsl(int $domainId, LetsEncryptSslData $data): void
    {
        $this->client->request('post', "/v3/domains/{$domainId}/ssl/le", $data->toArray());
    }

    public function getSslCertificate(int $domainId): ?SslCertificateData
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}/ssl");
        $payload = data_get($response, 'payload');

        return is_array($payload) ? SslCertificateData::fromArray($payload) : null;
    }

    public function isSslReady(int $domainId): bool
    {
        return (bool) $this->getSslCertificate($domainId)?->isReady();
    }

    public function syncProxiedPorts(int $domainId, array $domainPorts): void
    {
        $this->client->request('put', "/v3/domains/{$domainId}/proxied-ports", [
            'domainPorts' => array_map(
                fn (ProxiedPortData $domainPort) => $domainPort->toArray(),
                $domainPorts
            ),
        ]);
    }

    private function serviceId(): int
    {
        return (int) config('services.stormwall.service_id');
    }
}
