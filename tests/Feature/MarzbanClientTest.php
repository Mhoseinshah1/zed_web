<?php

namespace Tests\Feature;

use App\Models\VpnPanel;
use App\Services\Marzban\MarzbanClient;
use App\Services\Marzban\MarzbanException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarzbanClientTest extends TestCase
{
    use RefreshDatabase;

    private function makePanel(array $attrs = []): VpnPanel
    {
        return VpnPanel::create(array_merge([
            'name'      => 'Test Panel',
            'type'      => VpnPanel::TYPE_MARZBAN,
            'base_url'  => 'https://panel.example.com',
            'username'  => 'admin',
            'password'  => 'secret123', // encrypted cast handles this
            'is_active' => true,
        ], $attrs));
    }

    private function fakeLoginSuccess(string $token = 'test-token-abc'): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => $token, 'token_type' => 'bearer'], 200),
        ]);
    }

    private function fakeLoginFailure(int $status = 401): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['detail' => 'Incorrect username or password'], $status),
        ]);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function test_login_returns_token_on_success(): void
    {
        $this->fakeLoginSuccess('my-jwt-token');

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $token  = $client->login();

        $this->assertEquals('my-jwt-token', $token);
    }

    public function test_login_throws_on_invalid_credentials(): void
    {
        $this->fakeLoginFailure(401);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);

        $this->expectException(MarzbanException::class);
        $client->login();
    }

    public function test_login_caches_token(): void
    {
        $this->fakeLoginSuccess('cached-token');

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $client->login();

        $this->assertEquals('cached-token', Cache::get("marzban_token_panel_{$panel->id}"));
    }

    // ── Test connection ───────────────────────────────────────────────────────

    public function test_test_connection_returns_system_info_on_success(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/system'      => Http::response(['version' => '0.3.2', 'mem_used' => 1024], 200),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $info   = $client->testConnection();

        $this->assertEquals('0.3.2', $info['version']);
    }

    public function test_test_connection_throws_when_system_endpoint_fails(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/system'      => Http::response(['detail' => 'Forbidden'], 403),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);

        $this->expectException(MarzbanException::class);
        $client->testConnection();
    }

    // ── Create user ───────────────────────────────────────────────────────────

    public function test_create_user_returns_marzban_user_response(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user'        => Http::response($this->fakeUserResponse('zpx_1_2_abcde'), 200),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $result = $client->createUser([
            'username'   => 'zpx_1_2_abcde',
            'proxies'    => ['vless' => new \stdClass()],
            'data_limit' => 0,
            'status'     => 'active',
        ]);

        $this->assertEquals('zpx_1_2_abcde', $result['username']);
        $this->assertEquals('active', $result['status']);
        $this->assertArrayHasKey('subscription_url', $result);
    }

    // ── Get user ──────────────────────────────────────────────────────────────

    public function test_get_user_returns_user_data(): void
    {
        Http::fake([
            '*/api/admin/token'         => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_1_2_abcde' => Http::response($this->fakeUserResponse('zpx_1_2_abcde'), 200),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $user   = $client->getUser('zpx_1_2_abcde');

        $this->assertEquals('zpx_1_2_abcde', $user['username']);
    }

    // ── Token refresh on 401 ──────────────────────────────────────────────────

    public function test_expired_token_triggers_refresh_and_retry(): void
    {
        $called = 0;

        Http::fake(function ($request) use (&$called) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'fresh-token', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/system')) {
                $called++;
                // First call returns 401, second call succeeds
                if ($called === 1) {
                    return Http::response(['detail' => 'Not authenticated'], 401);
                }
                return Http::response(['version' => '0.3.2'], 200);
            }
        });

        $panel  = $this->makePanel();
        Cache::put("marzban_token_panel_{$panel->id}", 'stale-token', 3500);

        $client = new MarzbanClient($panel);
        $info   = $client->testConnection(); // testConnection calls /api/system

        $this->assertEquals('0.3.2', $info['version']);
    }

    // ── Normalize response ────────────────────────────────────────────────────

    public function test_normalize_user_response_converts_bytes_to_gb(): void
    {
        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);

        $raw        = $this->fakeUserResponse('test', usedBytes: 5_368_709_120); // 5 GB
        $normalized = $client->normalizeUserResponse($raw);

        $this->assertEquals(5.0, $normalized['used_traffic_gb']);
    }

    public function test_extract_subscription_link_returns_url(): void
    {
        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);

        $response = $this->fakeUserResponse('test');
        $link     = $client->extractSubscriptionLink($response);

        $this->assertEquals('https://panel.example.com/sub/TOKEN123/', $link);
    }

    public function test_user_exists_returns_false_on_404(): void
    {
        Http::fake([
            '*/api/admin/token'          => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/nonexistent_user' => Http::response(['detail' => 'User not found'], 404),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);

        $this->assertFalse($client->userExists('nonexistent_user'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fakeUserResponse(string $username, int $usedBytes = 0): array
    {
        return [
            'username'         => $username,
            'status'           => 'active',
            'used_traffic'     => $usedBytes,
            'data_limit'       => 21_474_836_480, // 20 GB
            'expire'           => now()->addDays(30)->timestamp,
            'subscription_url' => 'https://panel.example.com/sub/TOKEN123/',
            'links'            => ['vless://some-config'],
            'proxies'          => ['vless' => ['id' => 'uuid-here']],
        ];
    }
}
