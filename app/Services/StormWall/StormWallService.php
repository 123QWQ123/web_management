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

    /**
     * Legacy one-shot setup: create/find the SW domain, assign protected IPs, add backend.
     * Used by older orchestration path; prefer the granular orchestrator methods for new code.
     */
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
            $this->addBackend($stormWallDomain->id, BackendData::fromConfig($domain->server_ip, 80, 80));
            $this->addBackend($stormWallDomain->id, BackendData::fromConfig($domain->server_ip, 443, 80));
        }

        return $stormWallDomain;
    }

    /**
     * Create a new SW domain and immediately fetch its proxy IP.
     * Returns a StormWallDomainData with id, name, and ip populated.
     */
    public function createStormWallDomain(CreateDomainData $data): StormWallDomainData
    {
        $domain = $this->createDomain($data);
        $ip     = $this->getProxyIp($domain->id);

        return new StormWallDomainData($domain->id, $domain->name, $ip);
    }

    /**
     * Fetch the StormWall-assigned proxy IP for a domain.
     * Prefers a dedicated IP (isShared=false) over a shared one.
     * Returns null if no IPs are assigned yet.
     */
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

    /**
     * Add backend servers for the given SW domain.
     * Registers two backends: port 80 (HTTP) and port 443 (HTTPS).
     */
    public function addBackends(int $domainId, string $serverIp): void
    {
        $this->addBackend($domainId, BackendData::fromConfig($serverIp, 80, 80));
        $this->addBackend($domainId, BackendData::fromConfig($serverIp, 443, 80));
    }

    /**
     * List all backends configured for a SW domain.
     * Returns the raw payload array from StormWall API.
     */
    public function listBackends(int $domainId): array
    {
        $serviceId = $this->serviceId();
        $response  = $this->client->request('get', "/v3/domains/{$domainId}/backends?serviceId={$serviceId}");

        return data_get($response, 'payload.results', data_get($response, 'payload', []));
    }

    /**
     * Delete a single backend by its ID from the given SW domain.
     */
    public function deleteBackend(int $domainId, int $backendId): void
    {
        $serviceId = $this->serviceId();
        $this->client->request('delete', "/v3/domains/{$domainId}/backends/{$backendId}?serviceId={$serviceId}");
    }

    /**
     * Atomically replace all existing backends with a single new backend pointing to $newIp.
     * Used when switching routing modes (e.g. cf_sw: replace backend with server_ip).
     * Deletion errors are silently swallowed — the new backend is always added regardless.
     */
    public function replaceBackends(int $domainId, string $newIp): void
    {
        $existing = $this->listBackends($domainId);

        foreach ($existing as $backend) {
            $id = $backend['id'] ?? null;
            if ($id) {
                try {
                    $this->deleteBackend($domainId, (int) $id);
                } catch (\Throwable) {
                    // Non-fatal: best-effort cleanup
                }
            }
        }

        $this->addBackend($domainId, BackendData::fromConfig($newIp, 80, 80));
        $this->addBackend($domainId, BackendData::fromConfig($newIp, 443, 80));
    }

    /**
     * Fetch full domain details from StormWall by ID.
     * Returns null if not found or API returns unexpected payload.
     */
    public function getDomain(int $domainId): ?StormWallDomainData
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}");
        $payload  = data_get($response, 'payload');

        return is_array($payload) ? StormWallDomainData::fromArray($payload) : null;
    }

    /**
     * Create a new domain in StormWall.
     * If the API does not return an ID in the response payload, falls back to searching by name.
     * Throws StormWallException if the domain cannot be found after creation.
     */
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

        // Fallback: search by name in case the API didn't return the created domain directly
        return $this->findDomain($data->name)
            ?? throw new StormWallException("StormWall domain [{$data->name}] was created, but its id was not returned.");
    }

    /**
     * Delete a domain from StormWall by its numeric ID.
     */
    public function deleteDomain(int $domainId): void
    {
        $this->client->request('delete', "/v3/domains/{$domainId}");
    }

    /**
     * Find a domain in StormWall by its exact domain name.
     * Searches up to 100 results; returns null if not found.
     */
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

    /**
     * Assign one or more protected IPs (the SW proxy IP) to a domain.
     * These are the IPs that SW will advertise for this domain.
     */
    public function assignProtectedIps(int $domainId, ProtectedIpsData $data): void
    {
        $this->client->request('post', "/v3/domains/{$domainId}/protected-ips", $data->toArray());
    }

    /**
     * Add a backend server to a SW domain using a typed DTO.
     * The backend DTO controls port, type, weight, SSL, and WebSocket settings.
     */
    public function addBackend(int $domainId, BackendData $data): void
    {
        $serviceId = $this->serviceId();
        $this->client->request('post', "/v3/domains/{$domainId}/backends?serviceId={$serviceId}", $data->toArray());
    }

    /**
     * Request a Let's Encrypt SSL certificate for the SW domain.
     * Certificate readiness must be polled separately via isSslReady().
     */
    public function requestLetsEncryptSsl(int $domainId, LetsEncryptSslData $data): void
    {
        $this->client->request('post', "/v3/domains/{$domainId}/ssl/le", $data->toArray());
    }

    /**
     * Fetch the current SSL certificate info for a domain.
     * Returns null if no certificate has been issued yet.
     */
    public function getSslCertificate(int $domainId): ?SslCertificateData
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}/ssl");
        $payload = data_get($response, 'payload');

        return is_array($payload) ? SslCertificateData::fromArray($payload) : null;
    }

    /**
     * Returns true if the SSL certificate for the domain is active and ready to serve traffic.
     */
    public function isSslReady(int $domainId): bool
    {
        return (bool) $this->getSslCertificate($domainId)?->isReady();
    }

    /**
     * Replace the full list of proxied ports for a domain (PUT — full replacement, not patch).
     */
    public function syncProxiedPorts(int $domainId, array $domainPorts): void
    {
        $this->client->request('put', "/v3/domains/{$domainId}/proxied-ports", [
            'domainPorts' => array_map(
                fn (ProxiedPortData $domainPort) => $domainPort->toArray(),
                $domainPorts
            ),
        ]);
    }

    /**
     * Enable HTTP→HTTPS redirect for a domain.
     * Must be called only after SSL certificate is active.
     */
    public function setHttpsRedirect(int $domainId): void
    {
        $this->client->request('post', "/v3/domains/{$domainId}/redirects", [
            'useRedirectToHttps'  => 1,
            'wwwRedirectMode'     => 0,
            'useExternalRedirect' => 0,
            'externalRedirectUrl' => '',
        ]);
    }

    /** Returns the StormWall service ID from config (required by most API endpoints). */
    private function serviceId(): int
    {
        return (int) config('services.stormwall.service_id');
    }

    /**
     * Returns the NS hostnames assigned to this domain by StormWall (e.g. dns1.storm-pro.net).
     * These should be set at the registrar when SW manages DNS for this domain.
     */
    public function getNameservers(int $domainId): array
    {
        $response = $this->client->request('get', "/v3/domains/{$domainId}/dns");
        $records  = data_get($response, 'payload', []);

        return collect($records)
            ->where('type', 'NS')
            ->pluck('record')
            ->values()
            ->all();
    }
}

