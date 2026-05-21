<?php

namespace App\Services\Cloudflare\DTO;
class DnsRecordData

{
    public function __construct(
        public string $id,
        public string $name,
        public string $content,
        public bool   $proxied,

    ){}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            content: $data['content'],
            proxied: $data['proxied'] ?? false,
        );
    }

}
