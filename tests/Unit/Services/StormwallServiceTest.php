<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StormwallServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_domain_and_returns_stormwall_domain_data(): void
    {
        config([
            'services.stormwall.api_key'       => 'test-key',
            'services.stormwall.base_url'      => 'https://api.stormwall.pro',
            'services.stormwall.service_id'    => 123,
            'services.stormwall.domain_port'   => 80,
            'services.stormwall.backend_port'  => 80,
            'services.stormwall.domain_uses_ssl' => false,
            'services.stormwall.backend_type'  => 'balance',
            'services.stormwall.backend_weight' => 50,
            'services.stormwall.use_proxy_sni' => false,
            'services.stormwall.retry.times'   => 1,
            'services.stormwall.retry.sleep'   => 0,
        ]);

        Http::fake([
            '*/v3/domains?serviceId=123'          => Http::response([
                'status'  => 'ok',
                'payload' => ['id' => 456, 'name' => 'example.com'],
            ], 201),
            '*/v3/domains/456/protected-ips*'     => Http::response(['status' => 'ok'], 200),
            '*/v3/domains/456/backends*'          => Http::response(['status' => 'ok'], 201),
            '*/v3/domains/456*'                   => Http::response([
                'status'  => 'ok',
                'payload' => ['id' => 456, 'name' => 'example.com', 'ip' => '185.71.67.102', 'backends' => []],
            ], 200),
        ]);

        $domain = Domain::create([
            'domain'     => 'example.com',
            'mode'       => 'cf_sw',
            'status'     => 'stormwall_backends',
            'server_ip'  => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $result = app(StormWallServiceInterface::class)->setup($domain);

        $this->assertSame(456, $result->id);
        $this->assertSame('example.com', $result->name);
        $this->assertSame('456', $domain->refresh()->stormwall_domain_id);

        Http::assertSent(fn ($r) =>
            $r->hasHeader('x-api-key', 'test-key') &&
            $r->method() === 'POST' &&
            str_contains($r->url(), '/v3/domains?serviceId=') &&
            ($r->data()['name'] ?? null) === 'example.com'
        );
    }
}
