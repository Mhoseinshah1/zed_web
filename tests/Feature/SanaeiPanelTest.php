<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\VpnPanels\MarzbanProvider;
use App\Services\VpnPanels\PanelProviderFactory;
use App\Services\VpnPanels\Sanaei\Sanaei3xUiClient;
use App\Services\VpnPanels\Sanaei3xUiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SanaeiPanelTest extends TestCase
{
    use RefreshDatabase;

    private function panel(array $overrides = []): VpnPanel
    {
        return VpnPanel::create(array_merge([
            'name'        => 'سنایی تست',
            'type'        => VpnPanel::TYPE_SANAEI_XUI,
            'base_url'    => 'https://panel.example.com:2053',
            'panel_path'  => '/M.hosein1384',
            'auth_method' => VpnPanel::AUTH_API_TOKEN,
            'api_token'   => 'secret-token-123',
            'default_inbound_id' => 1,
            'verify_ssl'  => true,
            'timeout_seconds' => 15,
            'is_active'   => true,
        ], $overrides));
    }

    private function service(VpnPanel $panel, array $overrides = []): UserService
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['traffic_gb' => 1, 'duration_days' => 30]);
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'plan_name'        => 'p',
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 1,
            'traffic_used_gb'  => 0,
        ], $overrides));
    }

    // ── Type / auth / security ───────────────────────────────────────────────

    public function test_panel_types_include_sanaei(): void
    {
        $this->assertArrayHasKey(VpnPanel::TYPE_SANAEI_XUI, VpnPanel::allTypes());
        $this->assertArrayHasKey(VpnPanel::AUTH_API_TOKEN, VpnPanel::authMethods());
        $this->assertArrayHasKey(VpnPanel::AUTH_API_LOGIN, VpnPanel::authMethods());
    }

    public function test_default_auth_method_is_api_token(): void
    {
        // DB default is api_token; an unknown/blank value also resolves to token.
        $panel = $this->panel();
        $panel->auth_method = '';
        $this->assertSame(VpnPanel::AUTH_API_TOKEN, $panel->effectiveAuthMethod());

        $login = $this->panel(['auth_method' => VpnPanel::AUTH_API_LOGIN]);
        $this->assertSame(VpnPanel::AUTH_API_LOGIN, $login->effectiveAuthMethod());
    }

    public function test_credentials_are_hidden_and_encrypted(): void
    {
        $panel = $this->panel();
        $array = $panel->toArray();
        $this->assertArrayNotHasKey('api_token', $array);
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('token', $array);

        // Stored encrypted (raw DB value differs from plaintext) but decrypts back.
        $raw = \DB::table('vpn_panels')->where('id', $panel->id)->value('api_token');
        $this->assertNotSame('secret-token-123', $raw);
        $this->assertSame('secret-token-123', $panel->fresh()->api_token);
    }

    public function test_marzban_panel_still_resolves_marzban_provider(): void
    {
        $marzban = VpnPanel::create(['name' => 'مرزبان', 'type' => VpnPanel::TYPE_MARZBAN, 'base_url' => 'https://m.example.com']);
        $this->assertInstanceOf(MarzbanProvider::class, PanelProviderFactory::forPanel($marzban));
        $this->assertInstanceOf(Sanaei3xUiProvider::class, PanelProviderFactory::forPanel($this->panel()));
    }

    // ── URL building ─────────────────────────────────────────────────────────

    public function test_url_is_built_with_panel_path_without_double_slashes(): void
    {
        $client = new Sanaei3xUiClient($this->panel());
        $this->assertSame(
            'https://panel.example.com:2053/M.hosein1384/panel/api/inbounds/list',
            $client->url('/panel/api/inbounds/list'),
        );
        // panel_path is preserved and not stripped.
        $this->assertStringContainsString('/M.hosein1384/', $client->url('panel/api/inbounds/list'));
    }

    // ── Auth: token + login ──────────────────────────────────────────────────

    public function test_test_connection_with_bearer_token(): void
    {
        Http::fake(['*/panel/api/inbounds/list' => Http::response(['success' => true, 'obj' => []], 200)]);

        $this->assertTrue((new Sanaei3xUiClient($this->panel()))->testConnection());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token-123')
            && str_contains($request->url(), '/M.hosein1384/panel/api/inbounds/list'));
    }

    public function test_test_connection_with_api_login_fallback(): void
    {
        Http::fake([
            '*/login' => Http::response(['success' => true], 200),
            '*/panel/api/inbounds/list' => Http::response(['success' => true, 'obj' => []], 200),
        ]);

        $panel = $this->panel(['auth_method' => VpnPanel::AUTH_API_LOGIN, 'api_token' => null, 'username' => 'admin', 'password' => 'pass']);
        $this->assertTrue((new Sanaei3xUiClient($panel))->testConnection());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/M.hosein1384/login'));
    }

    public function test_get_inbounds_parses_list(): void
    {
        Http::fake(['*/panel/api/inbounds/list' => Http::response([
            'success' => true,
            'obj' => [['id' => 1, 'remark' => 'main', 'protocol' => 'vless', 'port' => 443]],
        ], 200)]);

        $inbounds = (new Sanaei3xUiClient($this->panel()))->getInbounds();
        $this->assertCount(1, $inbounds);
        $this->assertSame('main', $inbounds[0]['remark']);
    }

    // ── Provisioning ─────────────────────────────────────────────────────────

    public function test_provision_creates_client_and_fills_service(): void
    {
        Http::fake([
            '*/panel/api/clients/get/*'   => Http::response(['success' => false], 200), // not found → create
            '*/panel/api/clients/add'     => Http::response(['success' => true], 200),
            '*/panel/api/clients/links/*' => Http::response(['success' => true, 'obj' => ['vless://abc']], 200),
        ]);

        $service = $this->service($this->panel());
        $result  = (new Sanaei3xUiProvider())->provision($service);

        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertNotEmpty($service->remote_username);
        $this->assertNotEmpty($service->remote_uuid);
        $this->assertNotNull($service->expires_at);
        $this->assertSame(UserService::SYNC_SYNCED, $service->sync_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/panel/api/clients/add')
            && str_contains((string) $r->body(), 'settings'));
    }

    public function test_provision_is_idempotent_when_client_exists(): void
    {
        Http::fake([
            '*/panel/api/clients/get/*'     => Http::response(['success' => true, 'obj' => ['email' => 'zed-1']], 200),
            '*/panel/api/clients/traffic/*' => Http::response(['success' => true, 'obj' => ['up' => 0, 'down' => 0, 'total' => 0]], 200),
            '*/panel/api/clients/add'       => Http::response(['success' => true], 200),
        ]);

        $service = $this->service($this->panel(), ['remote_username' => 'zed-existing']);
        $result  = (new Sanaei3xUiProvider())->provision($service);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['existed'] ?? false);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/panel/api/clients/add'));
    }

    // ── Sync ─────────────────────────────────────────────────────────────────

    public function test_sync_updates_traffic_expiry_status(): void
    {
        $futureMs = now()->addDays(15)->getTimestampMs();
        Http::fake(['*/panel/api/clients/traffic/*' => Http::response([
            'success' => true,
            'obj' => ['up' => 100, 'down' => 200, 'total' => 1073741824, 'expiryTime' => $futureMs, 'enable' => true],
        ], 200)]);

        $service = $this->service($this->panel(), ['remote_username' => 'zed-1']);
        $result  = (new Sanaei3xUiProvider())->sync($service);

        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertSame(300, $service->marzban_used_traffic);
        $this->assertSame(1073741824, $service->marzban_data_limit);
        $this->assertSame('active', $service->remote_status);
        $this->assertSame(UserService::SYNC_SYNCED, $service->sync_status);
    }

    public function test_sync_failure_keeps_local_data(): void
    {
        Http::fake(['*/panel/api/clients/traffic/*' => Http::response(['success' => false], 500)]);
        $service = $this->service($this->panel(), ['remote_username' => 'zed-1', 'marzban_used_traffic' => 42]);

        $result = (new Sanaei3xUiProvider())->sync($service);
        $this->assertFalse($result->ok);
        $service->refresh();
        $this->assertSame(UserService::SYNC_FAILED, $service->sync_status);
        $this->assertSame(42, $service->marzban_used_traffic); // local data preserved
    }

    // ── Extra traffic / time ─────────────────────────────────────────────────

    public function test_add_traffic_increases_quota_without_resetting_usage(): void
    {
        Http::fake([
            '*/panel/api/clients/traffic/*'  => Http::response(['success' => true, 'obj' => ['total' => 1073741824]], 200),
            '*/panel/api/clients/update/*'   => Http::response(['success' => true], 200),
        ]);
        $service = $this->service($this->panel(), ['remote_username' => 'zed-1', 'remote_uuid' => 'uuid-1', 'marzban_used_traffic' => 500]);

        $result = (new Sanaei3xUiProvider())->addTraffic($service, 2 * 1073741824);
        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertSame(3 * 1073741824, $service->marzban_data_limit);
        $this->assertSame(500, $service->marzban_used_traffic); // usage untouched
    }

    public function test_add_time_extends_existing_expiry(): void
    {
        Http::fake(['*/panel/api/clients/update/*' => Http::response(['success' => true], 200)]);
        $service = $this->service($this->panel(), ['remote_username' => 'zed-1', 'remote_uuid' => 'uuid-1', 'expires_at' => now()->addDays(5)]);

        $before = $service->expires_at->copy();
        $result = (new Sanaei3xUiProvider())->addTime($service, 10);
        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertEqualsWithDelta(15, now()->diffInDays($service->expires_at, false), 1);
        $this->assertTrue($service->expires_at->greaterThan($before));
    }

    // ── Capability gating ────────────────────────────────────────────────────

    public function test_unsupported_panel_type_is_flagged(): void
    {
        $this->assertFalse(PanelProviderFactory::isSupported(VpnPanel::TYPE_XUI));
        $this->assertTrue(PanelProviderFactory::isSupported(VpnPanel::TYPE_SANAEI_XUI));
        $this->assertTrue(PanelProviderFactory::isSupported(VpnPanel::TYPE_MARZBAN));
    }
}
