<?php

namespace App\Services\StormWall\DTO;

final readonly class CreateDomainData
{
    public function __construct(
        public string $name
    ) {}

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'domainPorts' => [
                ['port' => 80,  'useSsl' => false, 'isWs' => false],
                ['port' => 443, 'useSsl' => true,  'isWs' => false],
            ],
        ];
    }
}
