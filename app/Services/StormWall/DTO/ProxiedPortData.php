<?php

namespace App\Services\StormWall\DTO;

final readonly class ProxiedPortData
{
    /**
     * @param  array<int, ProxiedPortBackendData>  $backends
     */
    public function __construct(
        public int $port,
        public bool $useSsl = false,
        public bool $isWs = false,
        public array $backends = []
    ) {}

    public function toArray(): array
    {
        return [
            'port' => $this->port,
            'useSsl' => $this->useSsl,
            'isWs' => $this->isWs,
            'backends' => array_map(
                fn (ProxiedPortBackendData $backend) => $backend->toArray(),
                $this->backends
            ),
        ];
    }
}
