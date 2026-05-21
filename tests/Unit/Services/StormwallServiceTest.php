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

    public function test_it_sets_up_domain_backend_and_protected_ip(): void
    {
        config([
            'services.stormwall.api_key' => 'test-key',
            'services.stormwall.base_url' => 'https://api.stormwall.pro',
            'services.stormwall.service_id' => 123,
            'services.stormwall.domain_port' => 80,
            'services.stormwall.backend_port' => 8080,
            'services.stormwall.domain_uses_ssl' => false,
            'services.stormwall.backend_type' => 'balance',
            'services.stormwall.backend_weight' => 1,
            'services.stormwall.use_proxy_sni' => false,
            'services.stormwall.retry.times' => 1,
            'services.stormwall.retry.sleep' => 0,
        ]);

        Http::fake([
            'api.stormwall.pro/v3/domains?serviceId=123' => Http::response([
                'status' => 'ok',
                'payload' => ['id' => 456],
            ], 201),
            'api.stormwall.pro/v3/domains/456/protected-ips' => Http::response([], 201),
            'api.stormwall.pro/v3/domains/456/backends' => Http::response([], 201),
        ]);

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => 'stormwall_setup',
            'server_ip' => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $stormWallDomain = app(StormWallServiceInterface::class)->setup($domain);

        $this->assertSame(456, $stormWallDomain->id);
        $this->assertSame('example.com', $stormWallDomain->name);
        $this->assertSame('456', $domain->refresh()->stormwall_domain_id);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-key')
                && $request->method() === 'POST'
                && $request->url() === 'https://api.stormwall.pro/v3/domains?serviceId=123'
                && $request['name'] === 'example.com';
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.stormwall.pro/v3/domains/456/protected-ips'
                && $request['ips'] === ['198.51.100.20'];
        });

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.stormwall.pro/v3/domains/456/backends'
                && $request['backend']['ip'] === '203.0.113.10'
                && $request['backend']['port'] === 8080
                && $request['domain']['port'] === 80;
        });
    }
}
