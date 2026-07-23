<?php

namespace Tests\Feature;

use App\Services\Health\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The public /health endpoint must expose ONLY safe booleans — never exception
 * messages, stack traces, paths, hostnames, endpoints, or credentials — and
 * return 503 when unhealthy. Detailed diagnostics live behind the artisan
 * command instead.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private const HEALTHY = [
        'app' => true, 'database' => true, 'redis' => true,
        'migrations' => true, 'storage' => true,
    ];

    /** Bind a health service whose boolean view we fully control. */
    private function fakeHealth(array $booleans): void
    {
        $mock = Mockery::mock(HealthCheckService::class);
        $mock->shouldReceive('booleans')->andReturn($booleans);
        $this->app->instance(HealthCheckService::class, $mock);
    }

    // ── Public endpoint shape ─────────────────────────────────────────────────

    public function test_all_healthy_returns_200_with_safe_booleans(): void
    {
        $this->fakeHealth(self::HEALTHY);

        $this->withoutMiddleware(ThrottleRequests::class)
            ->get('/health')
            ->assertOk()
            ->assertExactJson([
                'status'     => 'ok',
                'app'        => true,
                'database'   => true,
                'redis'      => true,
                'migrations' => true,
                'storage'    => true,
            ]);
    }

    /**
     * Every one of the 32 healthy/unhealthy combinations: 200 iff all true,
     * else 503 — and never any error/exception field in the body.
     */
    public function test_all_combinations_expose_only_booleans(): void
    {
        $keys = ['app', 'database', 'redis', 'migrations', 'storage'];

        // Bind ONE service whose boolean view we mutate per iteration — rebinding
        // a fresh instance mid-test does not take effect after the first resolve.
        $current = array_fill_keys($keys, true);
        $mock = Mockery::mock(HealthCheckService::class);
        $mock->shouldReceive('booleans')->andReturnUsing(function () use (&$current) {
            return $current;
        });
        $this->app->instance(HealthCheckService::class, $mock);

        for ($mask = 0; $mask < 32; $mask++) {
            $booleans = [];
            foreach ($keys as $bit => $key) {
                $booleans[$key] = (bool) ($mask & (1 << $bit));
            }
            $allOk = ! in_array(false, $booleans, true);
            $current = $booleans;

            $res = $this->withoutMiddleware(ThrottleRequests::class)->get('/health');

            $res->assertStatus($allOk ? 200 : 503);

            $json = $res->json();
            $this->assertSame(array_keys($json), ['status', 'app', 'database', 'redis', 'migrations', 'storage'],
                "combination {$mask} leaked extra keys");
            foreach (['error', 'errors', 'exception', 'message', 'trace', 'file'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $json, "combination {$mask} exposed '{$forbidden}'");
            }
            $this->assertSame($allOk ? 'ok' : 'error', $json['status']);
        }
    }

    public function test_public_response_never_contains_exception_text(): void
    {
        // Storage check throws a message stuffed with secrets/endpoints.
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException(
            'SQLSTATE connection failed: password=hunter2 host=db.internal:5432 tcp://10.9.8.7:6379 /var/www/app/storage'
        ));

        $res = $this->withoutMiddleware(ThrottleRequests::class)->get('/health');

        $res->assertStatus(503);
        foreach (['hunter2', 'db.internal', '10.9.8.7', '6379', '5432', '/var/www', 'SQLSTATE', 'password='] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $res->getContent());
        }
    }

    // ── Headers, liveness, rate limit ─────────────────────────────────────────

    public function test_health_sets_noindex_header(): void
    {
        $this->fakeHealth(self::HEALTHY);

        $this->withoutMiddleware(ThrottleRequests::class)
            ->get('/health')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_liveness_probe_is_always_ok_and_safe(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class)
            ->get('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'app' => true]);
    }

    public function test_health_endpoint_is_rate_limited(): void
    {
        $this->fakeHealth(self::HEALTHY);

        for ($i = 0; $i < 30; $i++) {
            $this->get('/health')->assertOk();
        }

        $this->get('/health')->assertStatus(429);
    }

    // ── Service-level: real failure is masked + logged ────────────────────────

    public function test_redis_failure_is_masked_and_logged(): void
    {
        Log::spy();

        // No Redis server in the test/CI environment → a real connection error
        // whose message would contain an endpoint. It must be masked.
        $result = app(HealthCheckService::class)->componentRedis();

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString('6379', $result['error']);
        $this->assertStringNotContainsString('127.0.0.1', $result['error']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => $msg === 'health.check_failed' && ($ctx['component'] ?? null) === 'redis')
            ->atLeast()->once();
    }

    public function test_storage_and_migrations_checks_pass_on_healthy_app(): void
    {
        $health = app(HealthCheckService::class);

        $this->assertTrue($health->componentStorage()['ok']);
        $this->assertTrue($health->componentMigrations()['ok']); // RefreshDatabase migrated
        $this->assertTrue($health->componentDatabase()['ok']);

        // The storage check must not leave its temporary probe file behind.
        $leftovers = Storage::disk('local')->files('health');
        $this->assertSame([], array_filter($leftovers, fn ($f) => str_contains($f, '.write-test')));
    }

    public function test_missing_migrations_table_is_reported_without_leak(): void
    {
        Storage::disk('local'); // ensure disk resolves
        \Illuminate\Support\Facades\Schema::drop('migrations');

        $result = app(HealthCheckService::class)->componentMigrations();

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    // ── Artisan diagnostics command ───────────────────────────────────────────

    public function test_health_command_reports_overall_status_when_healthy(): void
    {
        $mock = Mockery::mock(HealthCheckService::class);
        $mock->shouldReceive('collect')->andReturn([
            'app' => ['ok' => true, 'error' => null],
            'database' => ['ok' => true, 'error' => null],
            'redis' => ['ok' => true, 'error' => null],
            'migrations' => ['ok' => true, 'error' => null],
            'storage' => ['ok' => true, 'error' => null],
        ]);
        $this->app->instance(HealthCheckService::class, $mock);

        $this->artisan('zedproxy:health')
            ->expectsOutputToContain('وضعیت کلی سیستم')
            ->assertExitCode(0);
    }

    public function test_health_command_reports_persian_failures_and_masks(): void
    {
        $mock = Mockery::mock(HealthCheckService::class);
        $mock->shouldReceive('collect')->andReturn([
            'app' => ['ok' => true, 'error' => null],
            'database' => ['ok' => false, 'error' => 'db down'],
            'redis' => ['ok' => false, 'error' => 'redis down'],
            'migrations' => ['ok' => true, 'error' => null],
            'storage' => ['ok' => false, 'error' => 'disk full'],
        ]);
        $this->app->instance(HealthCheckService::class, $mock);

        $this->artisan('zedproxy:health')
            ->expectsOutputToContain('اتصال دیتابیس برقرار نیست.')
            ->expectsOutputToContain('اتصال Redis برقرار نیست.')
            ->expectsOutputToContain('دسترسی نوشتن در فضای ذخیره‌سازی وجود ندارد.')
            ->assertExitCode(1);
    }
}
