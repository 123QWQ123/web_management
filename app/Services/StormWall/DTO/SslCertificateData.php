<?php

namespace App\Services\StormWall\DTO;

use Illuminate\Support\Carbon;

final readonly class SslCertificateData
{
    public function __construct(
        public ?string $certificate,
        public ?string $key,
        public ?int $port,
        public ?int $expirationTimestamp,
        public ?string $issuerCountry = null,
        public ?string $issuerOrganization = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            certificate: $data['sslCertificate'] ?? null,
            key: $data['sslKey'] ?? null,
            port: isset($data['sslPort']) ? (int) $data['sslPort'] : null,
            expirationTimestamp: isset($data['expirationTimestamp']) ? (int) $data['expirationTimestamp'] : null,
            issuerCountry: $data['issuerCountry'] ?? null,
            issuerOrganization: $data['issuerOrganization'] ?? null,
        );
    }

    public function isReady(): bool
    {
        if (! $this->certificate || ! $this->key) {
            return false;
        }

        if (! $this->expirationTimestamp) {
            return true;
        }

        return Carbon::createFromTimestamp($this->expirationTimestamp)->isFuture();
    }
}
