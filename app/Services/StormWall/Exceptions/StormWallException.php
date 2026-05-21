<?php

namespace App\Services\StormWall\Exceptions;

use RuntimeException;

class StormWallException extends RuntimeException
{
    public static function requestFailed(int $status, string $message): self
    {
        return new self("StormWall API request failed with HTTP {$status}: {$message}", $status);
    }

    public static function softErrors(array $errors): self
    {
        return new self('StormWall API returned soft errors: '.json_encode($errors));
    }
}
