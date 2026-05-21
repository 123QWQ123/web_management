<?php

namespace App\Services\StormWall\Http;

use App\Services\StormWall\Exceptions\StormWallException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class StormWallClient
{
    public function request(string $method, string $uri, array $data = []): array
    {
        $response = $this->http()->{$method}($uri, $this->payloadFor($method, $data));

        $this->log($method, $uri, $data, $response);

        if ($response->failed()) {
            $msg = $response->json('payload.message')
                ?? $response->json('message')
                ?? $response->body();
            throw StormWallException::requestFailed(
                $response->status(),
                is_array($msg) ? implode('; ', $msg) : (string) $msg
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            return [];
        }

        if (($json['status'] ?? 'ok') === 'error') {
            throw StormWallException::requestFailed(
                $response->status(),
                data_get($json, 'payload.message', 'Unknown StormWall API error')
            );
        }

        if (! empty($json['error_list'])) {
            throw StormWallException::softErrors($json['error_list']);
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.stormwall.base_url'))
            ->withHeader('x-api-key', config('services.stormwall.api_key'))
            ->acceptJson()
            ->asJson()
            ->retry(
                config('services.stormwall.retry.times'),
                config('services.stormwall.retry.sleep'),
                null,
                false  // do not auto-throw; we log first then throw StormWallException
            );
    }

    private function payloadFor(string $method, array $data): array
    {
        return in_array(strtolower($method), ['get', 'head'], true)
            ? $data
            : array_filter($data, fn ($value) => $value !== null);
    }

    private function log(string $method, string $uri, array $data, Response $response): void
    {
        \Illuminate\Support\Facades\Log::channel('domain')->info('StormWall API', [
            'method'   => strtoupper($method),
            'uri'      => $uri,
            'payload'  => $data,
            'status'   => $response->status(),
            'response' => $response->json(),
        ]);
    }
}
