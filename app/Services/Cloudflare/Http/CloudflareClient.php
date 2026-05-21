<?php

namespace App\Services\Cloudflare\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CloudflareClient
{
    public function request(string $method, string $uri, array $data = []): array
    {
        $response = Http::withToken(config('services.cloudflare.token'))
            ->baseUrl(config('services.cloudflare.base_url'))
            ->retry(3, 500)
            ->acceptJson()
            ->$method($uri, $data);

        $this->log($method, $uri, $data, $response);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Cloudflare API error ['.$response->status().']: '.$response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function log(string $method, string $uri, array $data, \Illuminate\Http\Client\Response $response): void
    {
        \Illuminate\Support\Facades\Log::channel('domain')->info('Cloudflare API', [
            'method'   => strtoupper($method),
            'uri'      => $uri,
            'payload'  => $data,
            'status'   => $response->status(),
            'response' => $response->json(),
        ]);
    }
}
