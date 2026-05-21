<?php

namespace App\Services\Cloudflare\DTO;

class ZoneData

{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public array $nameservers = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            status: $data['status'],
            nameservers: $data['name_servers'] ?? [],
        );
    }

}
