<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login as AdminLogin;
use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Layer A — mandatory panel-login MFA: pending-auth semantics, forced
 * enrollment, TOTP/recovery challenges, session regeneration, denial of all
 * panel entry points before completion.
 */
class AdminMfaLoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'mfaadmin'.fake()->unique()->numberBetween(1, 999999),
            'password' => bcrypt('secret123'),
            'is_admin' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function totp(): AdminTotpService
    {
        return app(AdminTotpService::class);
    }

    private function engine(): Google2FA
    {
        return app(Google2FA::class);
    }

    /** Put the session into the password-verified pending state. */
    private function pendingFor(User $user): static
    {
        return $this->withSession([
            AdminMfaSession::PENDING_KEY => [
                'user_id' => $user->id,
                'remember' => false,
                'expires_at' => now()->addMinutes(AdminMfaSession::PENDING_TTL_MINUTES)->getTimestamp(),
            ],
        ]);
    }

    // ── Forced enrollment ────────────────────────────────────────────────────

    public function test_existing_admin_without_mfa_is_forced_to_enroll(): void
    {
        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->fillForm(['username' => $admin->username, 'password' => 'secret123'])
            ->call('authenticate')
            ->assertRedirect(route('zed-admin.mfa.challenge'));

        $this->assertGuest();

        // The challenge routes an un-enrolled subject into forced enrollment.
        $this->pendingFor($admin)
            ->get(route('zed-admin.mfa.challenge'))
            ->assertRedirect(route('zed-admin.mfa.enroll'));
    }

    public function test_enrollment_page_renders_local_qr_and_manual_key_with_no_cache(): void
    {
        $admin = $this->admin();

        $response = $this->pendingFor($admin)->get(route('zed-admin.mfa.enroll'));

        $response->assertOk();
        $response->assertSee('<svg', false); // QR rendered locally as inline SVG
        $response->assertDontSee('chart.googleapis');
        $response->assertDontSee('api.qrserver');
        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // The pending secret is on display (that IS the one-time setup), and
        // the provisioning URI uses the stable local issuer.
        $cred = $this->totp()->credentialFor($admin);
        $uri = $this->totp()->provisioningUri($admin, $cred->pending_secret);
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString(rawurlencode(AdminTotpService::ISSUER), $uri);
        $this->assertStringContainsString(rawurlencode($admin->username), $uri);
    }

    public function test_dashboard_is_inaccessible_before_enrollment_confirms(): void
    {
        $admin = $this->admin();

        // Pending (password proven) but NOT authenticated: the panel rejects.
        $this->pendingFor($admin)->get('/zed-admin')->assertRedirectContains('/zed-admin/login');
        $this->assertGuest();
    }

    public function test_valid_code_confirms_enrollment_shows_hashed_only_recovery_codes_then_logs_in(): void
    {
        $admin = $this->admin();
        $this->pendingFor($admin)->get(route('zed-admin.mfa.enroll'))->assertOk();

        $pending = $this->totp()->credentialFor($admin)->pending_secret;
        $code = $this->engine()->getCurrentOtp($pending);

        $confirm = $this->post(route('zed-admin.mfa.enroll.confirm'), ['code' => $code]);
        $confirm->assertOk();
        $this->assertStringContainsString('no-store', (string) $confirm->headers->get('Cache-Control'));

        // Recovery codes displayed exactly once — and stored only as hashes.
        $this->assertSame(AdminTotpService::RECOVERY_CODE_COUNT, $this->totp()->recoveryCodesRemaining($admin));
        preg_match_all('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $confirm->getContent(), $matches);
        $this->assertCount(AdminTotpService::RECOVERY_CODE_COUNT, array_unique($matches[0]));
        $rawRow = json_encode((array) DB::table('admin_two_factor_credentials')->where('user_id', $admin->id)->first());
        foreach ($matches[0] as $displayed) {
            $this->assertStringNotContainsString($displayed, $rawRow);
        }

        // Still not authenticated until explicit acknowledgement.
        $this->assertGuest();

        $this->post(route('zed-admin.mfa.enroll.acknowledge'))->assertRedirect('/zed-admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(AdminMfaSession::markerValid($admin->fresh()));
    }

    public function test_wrong_code_does_not_confirm_enrollment(): void
    {
        $admin = $this->admin();
        $this->pendingFor($admin)->get(route('zed-admin.mfa.enroll'))->assertOk();

        $this->post(route('zed-admin.mfa.enroll.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->totp()->hasConfirmedCredential($admin));
        $this->assertGuest();
    }

    // ── TOTP challenge ───────────────────────────────────────────────────────

    public function test_password_plus_valid_totp_creates_an_authenticated_marked_session(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        $this->pendingFor($admin)->get(route('zed-admin.mfa.challenge'))->assertOk();

        $this->post(route('zed-admin.mfa.verify'), ['code' => $this->currentAdminTotpCode($admin)])
            ->assertRedirect('/zed-admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(AdminMfaSession::markerValid($admin->fresh()));
        $this->assertNull(session(AdminMfaSession::PENDING_KEY), 'pending state cleared after success');

        // The authenticated session now passes the panel gate.
        $this->get('/zed-admin')->assertOk();
    }

    public function test_invalid_totp_never_authenticates(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        $this->pendingFor($admin)
            ->post(route('zed-admin.mfa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_replayed_login_code_fails_on_the_second_login(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        $code = $this->currentAdminTotpCode($admin);

        $this->pendingFor($admin)->post(route('zed-admin.mfa.verify'), ['code' => $code])
            ->assertRedirect('/zed-admin');
        $this->assertAuthenticatedAs($admin);

        // Fresh session, same code: replay refused.
        $this->post('/logout');
        $this->assertGuest();

        $this->pendingFor($admin)->post(route('zed-admin.mfa.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_password_stage_issues_no_remember_cookie(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        Livewire::test(AdminLogin::class)
            ->fillForm(['username' => $admin->username, 'password' => 'secret123', 'remember' => true])
            ->call('authenticate')
            ->assertRedirect(route('zed-admin.mfa.challenge'));

        $this->assertGuest();

        // No remember_* cookie may exist before MFA completes.
        foreach (app('cookie')->getQueuedCookies() as $cookie) {
            $this->assertStringNotContainsString('remember', $cookie->getName());
        }
    }

    // ── Denial of panel entry points ─────────────────────────────────────────

    public function test_authenticated_admin_without_marker_is_pushed_to_the_challenge(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        // e.g. an admin who authenticated through the CUSTOMER login form.
        $this->actingAsWithoutAdminMfa($admin)
            ->get('/zed-admin')
            ->assertRedirect(route('zed-admin.mfa.challenge'));

        // …and can complete MFA there without re-entering the password.
        $this->post(route('zed-admin.mfa.verify'), ['code' => $this->currentAdminTotpCode($admin)])
            ->assertRedirect('/zed-admin');
        $this->get('/zed-admin')->assertOk();
    }

    public function test_livewire_style_requests_without_marker_get_403(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        $this->actingAsWithoutAdminMfa($admin)
            ->get('/zed-admin', ['X-Livewire' => 'true'])
            ->assertForbidden();
    }

    public function test_marker_for_another_user_or_stale_credential_version_is_rejected(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        $this->provisionConfirmedAdminTotp($other);

        // Marker minted for OTHER user.
        $this->actingAsWithoutAdminMfa($admin)
            ->withSession([AdminMfaSession::MARKER_KEY => $this->adminMfaMarker($other)])
            ->get('/zed-admin')
            ->assertRedirect(route('zed-admin.mfa.challenge'));

        // Marker bound to a credential version that was since reset.
        $marker = $this->adminMfaMarker($admin);
        $this->totp()->resetFor($admin);
        $this->actingAsWithoutAdminMfa($admin)
            ->withSession([AdminMfaSession::MARKER_KEY => $marker])
            ->get('/zed-admin')
            ->assertRedirect(route('zed-admin.mfa.challenge'));
    }

    public function test_non_admin_cannot_use_the_mfa_routes(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'password' => bcrypt('secret123')]);

        $this->actingAs($user)->get(route('zed-admin.mfa.enroll'))->assertRedirect('/zed-admin/login');
        $this->actingAs($user)->get(route('zed-admin.mfa.challenge'))->assertRedirect('/zed-admin/login');
        $this->assertNull($this->totp()->credentialFor($user));
    }

    public function test_expired_pending_state_is_cleared_and_denied(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        $this->withSession([
            AdminMfaSession::PENDING_KEY => [
                'user_id' => $admin->id,
                'remember' => false,
                'expires_at' => now()->subMinute()->getTimestamp(),
            ],
        ])->post(route('zed-admin.mfa.verify'), ['code' => $this->currentAdminTotpCode($admin)])
            ->assertRedirect('/zed-admin/login');

        $this->assertGuest();
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    public function test_recovery_code_logs_in_once_and_never_twice(): void
    {
        $admin = $this->admin();
        $enrollment = $this->totp()->startEnrollment($admin);
        $codes = $this->totp()->confirmEnrollment($admin, $this->engine()->getCurrentOtp($enrollment['secret']))['codes'];

        $this->pendingFor($admin)->get(route('zed-admin.mfa.recovery'))->assertOk();
        $this->post(route('zed-admin.mfa.recovery.verify'), ['recovery_code' => $codes[0]])
            ->assertRedirect('/zed-admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(AdminMfaSession::enteredViaRecovery());

        // Reuse refused.
        $this->post('/logout');
        $this->pendingFor($admin)->post(route('zed-admin.mfa.recovery.verify'), ['recovery_code' => $codes[0]])
            ->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    // ── Rate limits (no enumeration) ─────────────────────────────────────────

    public function test_totp_challenge_is_rate_limited_with_a_generic_response(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        foreach (range(1, 5) as $i) {
            $this->pendingFor($admin)
                ->post(route('zed-admin.mfa.verify'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->pendingFor($admin)
            ->post(route('zed-admin.mfa.verify'), ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_login_failure_is_one_generic_message_for_every_cause(): void
    {
        $this->admin(['username' => 'realadmin']);
        User::factory()->create(['username' => 'plainuser', 'password' => bcrypt('secret123'), 'is_admin' => false]);

        $unknownUser = Livewire::test(AdminLogin::class)
            ->fillForm(['username' => 'nosuchuser', 'password' => 'secret123'])
            ->call('authenticate')->errors()->get('data.username');
        $wrongPassword = Livewire::test(AdminLogin::class)
            ->fillForm(['username' => 'realadmin', 'password' => 'wrong'])
            ->call('authenticate')->errors()->get('data.username');
        $nonAdmin = Livewire::test(AdminLogin::class)
            ->fillForm(['username' => 'plainuser', 'password' => 'secret123'])
            ->call('authenticate')->errors()->get('data.username');

        $this->assertSame($unknownUser, $wrongPassword, 'unknown username and wrong password are indistinguishable');
        $this->assertSame($unknownUser, $nonAdmin, 'admin-ness is not disclosed');
        $this->assertGuest();
    }

    public function test_admin_demoted_mid_session_loses_panel_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/zed-admin')->assertOk();

        $admin->forceFill(['is_admin' => false])->save();

        $this->get('/zed-admin')->assertForbidden();
    }
}
