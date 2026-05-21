<?php

namespace App\Services\StormWall\DTO;

final readonly class ProxiedPortBackendData
{
    public function __construct(
        public string $ip,
        public int $port,
        public ?int $weight = null,
        public ?string $type = null,
        public ?string $status = null,
        public ?bool $useSsl = null,
        public ?bool $useProxySni = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'ip' => $this->ip,
            'port' => $this->port,
            'weight' => $this->weight,
            'type' => $this->type,
            'status' => $this->status,
            'useSsl' => $this->useSsl,
            'useProxySni' => $this->useProxySni,
        ], fn ($value) => $value !== null);
    }
}
