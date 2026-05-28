<?php

namespace App\Services\StormWall\DTO;

final readonly class BackendData
{
    public function __construct(
        public string $ip,
        public int $backendPort,
        public int $domainPort,
        public string $type = 'balance',
        public string $status = 'enabled',
        public int $weight = 1,
        public bool $useSsl = false,
        public bool $useProxySni = false,
        public bool $isWs = false
    ) {}

    public static function fromConfig(string $ip): self
    {
        return new self(
            ip: $ip,
            backendPort: (int) config('services.stormwall.backend_port'),
            domainPort: (int) config('services.stormwall.domain_port'),
            type: (string) config('services.stormwall.backend_type'),
            weight: (int) config('services.stormwall.backend_weight'),
            useSsl: (bool) config('services.stormwall.domain_uses_ssl'),
            useProxySni: (bool) config('services.stormwall.use_proxy_sni')
        );
    }

    public function toArray(): array
    {
        return [
            'backend' => [
                'ip'          => $this->ip,
                'port'        => $this->backendPort,
                'type'        => $this->type,
                'status'      => $this->status,
                'useSsl'      => $this->useSsl,
                'useProxySni' => $this->useProxySni,
                // Note: 'isWs' is intentionally excluded — StormWall API rejects the field
                'weight'      => $this->weight,
            ],
            'domain' => [
                'port'   => $this->domainPort,
                'useSsl' => $this->useSsl,
            ],
        ];
    }
}
