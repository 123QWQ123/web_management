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
            'name' => $this->name,
        ];
    }
}
