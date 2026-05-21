<?php

namespace App\Services\StormWall\DTO;

final readonly class ProtectedIpsData
{
    /**
     * @param  array<int, string>  $ips
     */
    public function __construct(
        public array $ips
    ) {}

    public static function fromIp(string $ip): self
    {
        return new self([$ip]);
    }

    public function toArray(): array
    {
        return [
            'ips' => array_values($this->ips),
        ];
    }
}
