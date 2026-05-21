<?php

namespace App\Services\StormWall\DTO;

final readonly class StormWallDomainData
{
    public function __construct(
        public int     $id,
        public string  $name,
        public ?string $ip = null, // IP assigned by StormWall — use this for Cloudflare DNS
    ) {}

    public static function fromArray(array $data): self
    {
        // StormWall may return the proxy IP under different field names
        $ip = $data['ip']
            ?? $data['anycast_ip']
            ?? $data['protected_ip']
            ?? $data['proxy_ip']
            ?? null;

        return new self(
            id:   (int) $data['id'],
            name: (string) ($data['domain'] ?? $data['name'] ?? ''),
            ip:   $ip ? (string) $ip : null,
        );
    }
}
