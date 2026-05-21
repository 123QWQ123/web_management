<?php

namespace App\Services\StormWall\DTO;

final readonly class LetsEncryptSslData
{
    public function __construct(
        public bool $wwwIncluded = true
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            wwwIncluded: (bool) config('services.stormwall.ssl.www_included')
        );
    }

    public function toArray(): array
    {
        return [
            'wwwIncluded' => $this->wwwIncluded,
        ];
    }
}
