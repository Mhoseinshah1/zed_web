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

    // ── Login uses form-data, not JSON ────────────────────────────────────────

    public function test_login_uses_form_encoded_body_not_json(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                $this->assertStringContainsString(
                    'application/x-www-form-urlencoded',
                    $request->header('Content-Type')[0] ?? ''
                );
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
        });

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $client->login();
    }

    // ── createUser sends proxies as object {}, not array [] ──────────────────

    public function test_create_user_sends_proxies_as_object_not_array(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/user') && $request->method() === 'POST') {
                $body = $request->body();
                $this->assertIsObject(json_decode($body)->proxies->vless ?? null);
                return Http::response($this->fakeUserResponse('zpx_test'), 200);
            }
        });

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $client->createUser([
            'username'   => 'zpx_test',
            'proxies'    => ['vless' => new \stdClass()],
            'data_limit' => 0,
            'status'     => 'active',
        ]);
    }

    // ── createUser does NOT send inbounds ─────────────────────────────────────

    public function test_create_user_payload_has_no_inbounds_key(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/user') && $request->method() === 'POST') {
                $decoded = json_decode($request->body(), true);
                $this->assertArrayNotHasKey('inbounds', $decoded);
                return Http::response($this->fakeUserResponse('zpx_test'), 200);
            }
        });

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $client->createUser([
            'username'   => 'zpx_test',
            'proxies'    => ['vless' => new \stdClass()],
            'data_limit' => 0,
            'status'     => 'active',
        ]);
    }

    // ── reset traffic calls correct endpoint ──────────────────────────────────

    public function test_reset_traffic_calls_correct_endpoint(): void
    {
        Http::fake([
            '*/api/admin/token'          => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_test/reset'  => Http::response($this->fakeUserResponse('zpx_test'), 200),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $result = $client->resetTraffic('zpx_test');

        $this->assertEquals('zpx_test', $result['username']);
    }

    // ── revoke subscription calls correct endpoint ────────────────────────────

    public function test_revoke_subscription_calls_correct_endpoint(): void
    {
        $newSubUrl = 'https://panel.example.com/sub/NEWTOKEN456/';

        Http::fake([
            '*/api/admin/token'              => Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200),
            '*/api/user/zpx_test/revoke_sub' => Http::response(
                array_merge($this->fakeUserResponse('zpx_test'), ['subscription_url' => $newSubUrl]),
                200
            ),
        ]);

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $result = $client->revokeSubscription('zpx_test');

        $this->assertEquals($newSubUrl, $result['subscription_url']);
    }

    // ── disable user sends status=disabled ────────────────────────────────────

    public function test_update_user_can_send_disabled_status(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/user/zpx_test') && $request->method() === 'PUT') {
                $body = json_decode($request->body(), true);
                $this->assertEquals('disabled', $body['status']);
                return Http::response(
                    array_merge($this->fakeUserResponse('zpx_test'), ['status' => 'disabled']),
                    200
                );
            }
        });

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $result = $client->updateUser('zpx_test', ['status' => 'disabled']);

        $this->assertEquals('disabled', $result['status']);
    }

    // ── enable user sends status=active ──────────────────────────────────────

    public function test_update_user_can_send_active_status(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/admin/token')) {
                return Http::response(['access_token' => 'tok', 'token_type' => 'bearer'], 200);
            }
            if (str_contains($request->url(), '/api/user/zpx_test') && $request->method() === 'PUT') {
                $body = json_decode($request->body(), true);
                $this->assertEquals('active', $body['status']);
                return Http::response($this->fakeUserResponse('zpx_test'), 200);
            }
        });

        $panel  = $this->makePanel();
        $client = new MarzbanClient($panel);
        $result = $client->updateUser('zpx_test', ['status' => 'active']);

        $this->assertEquals('active', $result['status']);
    }

    // ── forgetToken clears cache ──────────────────────────────────────────────

    public function test_forget_token_removes_from_cache(): void
    {
        $panel  = $this->makePanel();
        Cache::put("marzban_token_panel_{$panel->id}", 'cached-token', 3500);

        $client = new MarzbanClient($panel);
        $client->forgetToken();

        $this->assertNull(Cache::get("marzban_token_panel_{$panel->id}"));
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
