<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\VpnPanels\PanelProviderFactory;
use App\Services\VpnPanels\Remnawave\RemnawaveClient;
use App\Services\VpnPanels\RemnawaveProvider;
use App\Filament\Resources\VpnPanelResource\Pages\CreateVpnPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class RemnawaveProviderTest extends TestCase
{
    use RefreshDatabase;

    private function panel(array $overrides = []): VpnPanel
    {
        return VpnPanel::create(array_merge([
            'name'               => 'رمناویو تست',
            'type'               => VpnPanel::TYPE_REMNAWAVE,
            'base_url'           => 'https://panel.example.com',
            'api_token'          => 'jwt-token-123',
            'default_squad_uuid' => 'squad-uuid-1',
            'verify_ssl'         => true,
            'timeout_seconds'    => 15,
            'is_active'          => true,
        ], $overrides));
    }

    private function service(VpnPanel $panel, array $overrides = []): UserService
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['traffic_gb' => 10, 'duration_days' => 30]);
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'plan_name'        => 'p',
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'vpn_panel_id'     => $panel->id,
            'traffic_total_gb' => 10,
            'traffic_used_gb'  => 0,
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function userObj(array $overrides = []): array
    {
        return array_merge([
            'uuid'            => 'user-uuid-1',
            'shortUuid'       => 'short1',
            'username'        => 'zed-1',
            'status'          => 'ACTIVE',
            'trafficLimitBytes' => 10 * 1073741824,
            'expireAt'        => '2026-08-02T00:00:00.000Z',
            'subscriptionUrl' => 'https://panel.example.com/sub/short1',
            'userTraffic'     => ['usedTrafficBytes' => 0],
        ], $overrides);
    }

    // ── Type / factory ────────────────────────────────────────────────────────

    public function test_panel_types_include_remnawave(): void
    {
        $this->assertArrayHasKey(VpnPanel::TYPE_REMNAWAVE, VpnPanel::allTypes());
        $this->assertTrue(PanelProviderFactory::isSupported(VpnPanel::TYPE_REMNAWAVE));
        $this->assertInstanceOf(RemnawaveProvider::class, PanelProviderFactory::forPanel($this->panel()));
    }

    public function test_form_shows_remnawave_fields_and_hides_other_types(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        Livewire::actingAs($admin)
            ->test(CreateVpnPanel::class)
            ->fillForm(['type' => VpnPanel::TYPE_REMNAWAVE])
            ->assertFormFieldIsVisible('default_squad_uuid')
            ->assertFormFieldIsHidden('username')      // Marzban-only
            ->assertFormFieldIsHidden('panel_path');   // Sanaei-only
    }

    public function test_token_is_hidden_and_encrypted(): void
    {
        $panel = $this->panel();
        $this->assertArrayNotHasKey('api_token', $panel->toArray());

        $raw = \DB::table('vpn_panels')->where('id', $panel->id)->value('api_token');
        $this->assertNotSame('jwt-token-123', $raw);
        $this->assertSame('jwt-token-123', $panel->fresh()->api_token);
    }

    // ── Connection test ───────────────────────────────────────────────────────

    public function test_connection_uses_bearer_token(): void
    {
        Http::fake(['*/api/users/tags' => Http::response(['response' => []], 200)]);

        $result = (new RemnawaveProvider())->testConnection($this->panel());
        $this->assertTrue($result->ok);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer jwt-token-123')
            && str_contains($r->url(), '/api/users/tags'));
    }

    // ── Provisioning ──────────────────────────────────────────────────────────

    public function test_provision_creates_user_with_correct_body_and_stores_uuid(): void
    {
        Http::fake([
            '*/api/users/by-username/*' => Http::response(['message' => 'not found'], 404),
            '*/api/users'               => Http::response(['response' => $this->userObj()], 201),
        ]);

        $service = $this->service($this->panel());
        $result  = (new RemnawaveProvider())->provision($service);

        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertSame('user-uuid-1', $service->remote_uuid);
        $this->assertSame('zed-1', $service->remote_username);
        $this->assertSame('https://panel.example.com/sub/short1', $service->subscription_link);
        $this->assertSame(UserService::SYNC_SYNCED, $service->sync_status);

        Http::assertSent(function ($r) {
            if (! ($r->method() === 'POST' && str_ends_with($r->url(), '/api/users'))) {
                return false;
            }
            $b = $r->data();
            return $r->hasHeader('Authorization', 'Bearer jwt-token-123')
                && ($b['username'] ?? null) !== null
                && ($b['trafficLimitBytes'] ?? null) === 10 * 1073741824      // bytes
                && ($b['trafficLimitStrategy'] ?? null) === 'NO_RESET'
                && ($b['activeInternalSquads'] ?? null) === ['squad-uuid-1']
                // expireAt is an ISO-8601 date-time string, NOT epoch ms.
                && is_string($b['expireAt'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $b['expireAt']) === 1;
        });
    }

    public function test_provision_is_idempotent_when_user_exists(): void
    {
        Http::fake([
            '*/api/users/by-username/*' => Http::response(['response' => $this->userObj()], 200),
            '*/api/users'               => Http::response(['response' => $this->userObj()], 201),
        ]);

        $service = $this->service($this->panel(), ['remote_username' => 'zed-existing']);
        $result  = (new RemnawaveProvider())->provision($service);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->data['existed'] ?? false);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/users'));
    }

    // ── Update / add-ons ──────────────────────────────────────────────────────

    public function test_update_uses_patch_with_uuid(): void
    {
        Http::fake(['*/api/users' => Http::response(['response' => $this->userObj()], 200)]);

        $service = $this->service($this->panel(), ['remote_uuid' => 'user-uuid-1']);
        (new RemnawaveProvider())->update($service, ['trafficLimitBytes' => 5368709120]);

        Http::assertSent(function ($r) {
            $b = $r->data();
            return $r->method() === 'PATCH'
                && str_ends_with($r->url(), '/api/users')
                && ($b['uuid'] ?? null) === 'user-uuid-1'
                && ($b['trafficLimitBytes'] ?? null) === 5368709120;
        });
    }

    public function test_add_time_extends_expiry_with_iso_datetime(): void
    {
        Http::fake(['*/api/users' => Http::response(['response' => $this->userObj()], 200)]);

        $service = $this->service($this->panel(), ['remote_uuid' => 'user-uuid-1', 'expires_at' => now()->addDays(5)]);
        $result  = (new RemnawaveProvider())->addTime($service, 10);

        $this->assertTrue($result->ok);
        Http::assertSent(function ($r) {
            $b = $r->data();
            return $r->method() === 'PATCH'
                && ($b['uuid'] ?? null) === 'user-uuid-1'
                && is_string($b['expireAt'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $b['expireAt']) === 1;
        });
    }

    // ── Enable / disable / reset ──────────────────────────────────────────────

    public function test_enable_disable_reset_hit_action_endpoints(): void
    {
        Http::fake([
            '*/actions/enable'        => Http::response(['response' => ['uuid' => 'user-uuid-1']], 200),
            '*/actions/disable'       => Http::response(['response' => ['uuid' => 'user-uuid-1']], 200),
            '*/actions/reset-traffic' => Http::response(['response' => ['uuid' => 'user-uuid-1']], 200),
        ]);

        $service  = $this->service($this->panel(), ['remote_uuid' => 'user-uuid-1']);
        $provider = new RemnawaveProvider();

        $this->assertTrue($provider->enable($service)->ok);
        $this->assertTrue($provider->disable($service)->ok);
        $this->assertTrue($provider->resetTraffic($service)->ok);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/users/user-uuid-1/actions/enable'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/users/user-uuid-1/actions/disable'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/users/user-uuid-1/actions/reset-traffic'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_delete_uses_delete_endpoint_with_uuid(): void
    {
        Http::fake(['*/api/users/user-uuid-1' => Http::response(['response' => ['isDeleted' => true]], 200)]);

        $service = $this->service($this->panel(), ['remote_uuid' => 'user-uuid-1']);
        $result  = (new RemnawaveProvider())->delete($service);

        $this->assertTrue($result->ok);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/users/user-uuid-1'));
    }

    public function test_delete_is_idempotent_when_user_missing(): void
    {
        Http::fake(['*/api/users/*' => Http::response(['message' => 'not found'], 404)]);

        $service = $this->service($this->panel(), ['remote_uuid' => 'gone-uuid']);
        $result  = (new RemnawaveProvider())->delete($service);

        $this->assertTrue($result->ok); // 404 → idempotent success
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    public function test_sync_updates_traffic_and_expiry(): void
    {
        Http::fake(['*/api/users/user-uuid-1' => Http::response([
            'response' => $this->userObj([
                'trafficLimitBytes' => 21474836480,
                'userTraffic'       => ['usedTrafficBytes' => 300],
                'status'            => 'ACTIVE',
            ]),
        ], 200)]);

        $service = $this->service($this->panel(), ['remote_uuid' => 'user-uuid-1']);
        $result  = (new RemnawaveProvider())->sync($service);

        $this->assertTrue($result->ok);
        $service->refresh();
        $this->assertSame(300, $service->marzban_used_traffic);
        $this->assertSame(21474836480, $service->marzban_data_limit);
        $this->assertSame('ACTIVE', $service->remote_status);
        $this->assertSame(UserService::SYNC_SYNCED, $service->sync_status);
    }
}
