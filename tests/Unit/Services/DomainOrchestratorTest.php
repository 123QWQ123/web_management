<?php

namespace Tests\Unit\Services;

use App\Enums\DomainStatus;
use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use App\Services\Cloudflare\Contracts\CloudflareServiceInterface;
use App\Services\Cloudflare\DTO\DnsRecordData;
use App\Services\Cloudflare\DTO\ZoneData;
use App\Services\DomainOrchestrator;
use App\Services\StormWall\Contracts\StormWallServiceInterface;
use App\Services\StormWall\DTO\LetsEncryptSslData;
use App\Services\StormWall\DTO\StormWallDomainData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DomainOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_cloudflare_zone_and_dispatches_next_step(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf',
            'status' => DomainStatus::INIT->value,
            'server_ip' => '203.0.113.10',
        ]);

        $cloudflare = Mockery::mock(CloudflareServiceInterface::class);
        $cloudflare->shouldReceive('createZone')
            ->once()
            ->with('example.com')
            ->andReturn(new ZoneData('zone-1', 'example.com', 'pending'));

        $this->app->instance(CloudflareServiceInterface::class, $cloudflare);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::CLOUDFLARE_ZONE->value, $domain->status);
        $this->assertSame('zone-1', $domain->cloudflare_zone_id);
        $this->assertTrue($domain->logs()->where('step', DomainStatus::INIT->value)->where('success', true)->exists());

        Queue::assertPushed(ProcessDomainJob::class, fn (ProcessDomainJob $job) => $job->domainId === $domain->id);
    }

    public function test_it_routes_cloudflare_dns_directly_to_server_ip_in_proxy_mode(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf',
            'status' => DomainStatus::CLOUDFLARE_ZONE->value,
            'cloudflare_zone_id' => 'zone-1',
            'server_ip' => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $cloudflare = Mockery::mock(CloudflareServiceInterface::class);
        $cloudflare->shouldReceive('findDnsRecord')
            ->once()
            ->with('zone-1', 'example.com')
            ->andReturn(null);
        $cloudflare->shouldReceive('createDnsRecord')
            ->once()
            ->with('zone-1', 'example.com', '203.0.113.10', true)
            ->andReturn(new DnsRecordData('dns-1', 'example.com', '203.0.113.10', true));

        $this->app->instance(CloudflareServiceInterface::class, $cloudflare);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::CLOUDFLARE_DNS->value, $domain->status);
        $this->assertSame('dns-1', $domain->cloudflare_dns_id);

        Queue::assertPushed(ProcessDomainJob::class, fn (ProcessDomainJob $job) => $job->domainId === $domain->id);
    }

    public function test_it_routes_cloudflare_dns_to_stormwall_ip_in_dns_only_mode(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::CLOUDFLARE_ZONE->value,
            'cloudflare_zone_id' => 'zone-1',
            'server_ip' => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $cloudflare = Mockery::mock(CloudflareServiceInterface::class);
        $cloudflare->shouldReceive('findDnsRecord')
            ->once()
            ->with('zone-1', 'example.com')
            ->andReturn(new DnsRecordData('dns-1', 'example.com', '203.0.113.10', true));
        $cloudflare->shouldReceive('updateDnsRecord')
            ->once()
            ->with('zone-1', 'dns-1', '198.51.100.20', false)
            ->andReturn(new DnsRecordData('dns-1', 'example.com', '198.51.100.20', false));

        $this->app->instance(CloudflareServiceInterface::class, $cloudflare);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::CLOUDFLARE_DNS->value, $domain->status);
        $this->assertSame('dns-1', $domain->cloudflare_dns_id);
    }

    public function test_it_moves_from_cloudflare_dns_to_stormwall_setup_for_fallback_route(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::CLOUDFLARE_DNS->value,
        ]);

        app(DomainOrchestrator::class)->handle($domain);

        $this->assertSame(DomainStatus::STORMWALL_SETUP->value, $domain->refresh()->status);

        Queue::assertPushed(ProcessDomainJob::class, fn (ProcessDomainJob $job) => $job->domainId === $domain->id);
    }

    public function test_it_finishes_after_stormwall_setup(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::STORMWALL_SETUP->value,
            'server_ip' => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('setup')
            ->once()
            ->with(Mockery::on(fn (Domain $givenDomain) => $givenDomain->is($domain)))
            ->andReturn(new StormWallDomainData(456, 'example.com'));

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $this->assertSame(DomainStatus::DONE->value, $domain->refresh()->status);
        Queue::assertNotPushed(ProcessDomainJob::class);
    }

    public function test_it_moves_to_ssl_request_step_after_stormwall_setup_when_ssl_is_enabled(): void
    {
        Queue::fake();

        config(['services.stormwall.ssl.lets_encrypt_enabled' => true]);

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::STORMWALL_SETUP->value,
            'server_ip' => '203.0.113.10',
            'stormwall_ip' => '198.51.100.20',
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('setup')
            ->once()
            ->andReturn(new StormWallDomainData(456, 'example.com'));

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::STORMWALL_SSL_REQUESTED->value, $domain->status);
        $this->assertSame('456', $domain->stormwall_domain_id);

        Queue::assertPushed(ProcessDomainJob::class, fn (ProcessDomainJob $job) => $job->domainId === $domain->id);
    }

    public function test_it_requests_stormwall_ssl_and_schedules_polling(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07 12:00:00'));

        config([
            'services.stormwall.ssl.www_included' => true,
            'services.stormwall.ssl.poll_delay_seconds' => 300,
        ]);

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::STORMWALL_SSL_REQUESTED->value,
            'stormwall_domain_id' => '456',
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('requestLetsEncryptSsl')
            ->once()
            ->with(456, Mockery::type(LetsEncryptSslData::class));

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::WAITING_STORMWALL_SSL->value, $domain->status);
        $this->assertTrue($domain->ssl_requested_at->equalTo(Carbon::parse('2026-05-07 12:00:00')));
        $this->assertTrue($domain->next_attempt_at->equalTo(Carbon::parse('2026-05-07 12:05:00')));

        Queue::assertPushed(ProcessDomainJob::class, function (ProcessDomainJob $job) use ($domain) {
            return $job->domainId === $domain->id && $job->delay !== null;
        });

        Carbon::setTestNow();
    }

    public function test_it_completes_when_stormwall_ssl_is_ready(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::WAITING_STORMWALL_SSL->value,
            'stormwall_domain_id' => '456',
            'ssl_requested_at' => now()->subMinutes(10),
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('isSslReady')
            ->once()
            ->with(456)
            ->andReturn(true);

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::DONE->value, $domain->status);
        $this->assertNotNull($domain->ssl_ready_at);
        $this->assertNull($domain->next_attempt_at);
        Queue::assertNotPushed(ProcessDomainJob::class);
    }

    public function test_it_keeps_waiting_for_stormwall_ssl_without_counting_as_failure(): void
    {
        Queue::fake();

        config([
            'services.stormwall.ssl.poll_delay_seconds' => 300,
            'services.stormwall.ssl.max_wait_minutes' => 30,
        ]);

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::WAITING_STORMWALL_SSL->value,
            'stormwall_domain_id' => '456',
            'ssl_requested_at' => now()->subMinutes(10),
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('isSslReady')
            ->once()
            ->with(456)
            ->andReturn(false);

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::WAITING_STORMWALL_SSL->value, $domain->status);
        $this->assertSame(0, $domain->retries);
        $this->assertNotNull($domain->next_attempt_at);

        Queue::assertPushed(ProcessDomainJob::class, fn (ProcessDomainJob $job) => $job->domainId === $domain->id && $job->delay !== null);
    }

    public function test_it_fails_when_stormwall_ssl_wait_expires(): void
    {
        Queue::fake();

        config(['services.stormwall.ssl.max_wait_minutes' => 20]);

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf_sw',
            'status' => DomainStatus::WAITING_STORMWALL_SSL->value,
            'stormwall_domain_id' => '456',
            'ssl_requested_at' => now()->subMinutes(25),
        ]);

        $stormWall = Mockery::mock(StormWallServiceInterface::class);
        $stormWall->shouldReceive('isSslReady')
            ->once()
            ->with(456)
            ->andReturn(false);

        $this->app->instance(StormWallServiceInterface::class, $stormWall);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::FAILED->value, $domain->status);
        $this->assertSame(1, $domain->retries);
        $this->assertTrue($domain->logs()->where('step', DomainStatus::WAITING_STORMWALL_SSL->value)->where('success', false)->exists());
        Queue::assertNotPushed(ProcessDomainJob::class);
    }

    public function test_it_marks_domain_as_failed_and_logs_exception(): void
    {
        Queue::fake();

        $domain = Domain::create([
            'domain' => 'example.com',
            'mode' => 'cf',
            'status' => DomainStatus::CLOUDFLARE_ZONE->value,
            'cloudflare_zone_id' => 'zone-1',
        ]);

        app(DomainOrchestrator::class)->handle($domain);

        $domain->refresh();

        $this->assertSame(DomainStatus::FAILED->value, $domain->status);
        $this->assertSame(1, $domain->retries);
        $this->assertTrue($domain->logs()->where('step', DomainStatus::CLOUDFLARE_ZONE->value)->where('success', false)->exists());
        Queue::assertNotPushed(ProcessDomainJob::class);
    }
}
