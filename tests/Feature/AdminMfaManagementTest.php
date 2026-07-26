<?php

namespace Tests\Feature;

use App\Filament\Pages\AdminSecurityPage;
use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminStepUpService;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * MFA self-management page (امنیت حساب ادمین) + the emergency CLI reset.
 */
class AdminMfaManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'username' => 'secadmin'.fake()->unique()->numberBetween(1, 999999),
            'password' => bcrypt('secret123'),
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function totp(): AdminTotpService
    {
        return app(AdminTotpService::class);
    }

    public function test_security_page_shows_only_non_secret_state(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/zed-admin/security/admin-mfa');
        $response->assertOk();
        $response->assertSee('ورود دومرحله‌ای');
        $response->assertSee('کدهای بازیابی باقی‌مانده');

        // The encrypted secret must never appear in the page.
        $secret = $this->totp()->credentialFor($admin)->secret;
        $response->assertDontSee($secret);
    }

    public function test_recovery_code_regeneration_requires_password_and_fresh_totp(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin); // provisions credential + session marker

        // Wrong password → refused, nothing changes.
        $before = $this->totp()->credentialFor($admin)->recovery_codes;
        Livewire::actingAs($admin)->test(AdminSecurityPage::class)
            ->callAction('regenerateRecoveryCodes', [
                'current_password' => 'wrong-password',
                'totp_code' => $this->currentAdminTotpCode($admin),
            ]);
        $this->assertEquals($before, $this->totp()->credentialFor($admin)->recovery_codes);

        // Correct password + fresh code → new one-time-displayed set.
        $cred = $this->totp()->credentialFor($admin);
        $cred->forceFill(['last_verified_timestep' => max(0, (int) $cred->last_verified_timestep - 10)])->save();

        $component = Livewire::actingAs($admin)->test(AdminSecurityPage::class)
            ->callAction('regenerateRecoveryCodes', [
                'current_password' => 'secret123',
                'totp_code' => $this->currentAdminTotpCode($admin),
            ]);

        $codes = $component->get('freshRecoveryCodes');
        $this->assertCount(AdminTotpService::RECOVERY_CODE_COUNT, $codes);
        $this->assertNotEquals($before, $this->totp()->credentialFor($admin)->recovery_codes);

        // Explicit dismissal clears the one-time display.
        $component->call('dismissFreshRecoveryCodes')->assertSet('freshRecoveryCodes', null);
    }

    public function test_authenticator_replacement_confirms_new_factor_before_removing_old_and_kills_grants(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);
        $this->be($admin);
        $oldVersion = $this->totp()->credentialFor($admin)->version();

        $this->assertTrue(app(AdminStepUpService::class)->hasActiveGrant($admin));

        $component = Livewire::actingAs($admin)->test(AdminSecurityPage::class)
            ->callAction('startReplacement', [
                'current_password' => 'secret123',
                'totp_code' => $this->currentAdminTotpCode($admin),
            ])
            ->assertSet('replacing', true);

        // Old factor still confirmed while the new one is pending.
        $this->assertTrue($this->totp()->hasConfirmedCredential($admin));
        $this->assertSame($oldVersion, $this->totp()->credentialFor($admin)->version());

        $pending = $this->totp()->credentialFor($admin)->pending_secret;
        $component->set('replacement_code', app(Google2FA::class)->getCurrentOtp($pending))
            ->call('confirmReplacement')
            ->assertSet('replacing', false);

        $fresh = $component->get('freshRecoveryCodes');
        $this->assertCount(AdminTotpService::RECOVERY_CODE_COUNT, $fresh);

        // Version rotated → step-up grant dead, other sessions' markers dead;
        // THIS session's marker was re-stamped and stays valid.
        $this->assertNotSame($oldVersion, $this->totp()->credentialFor($admin)->version());
        $this->assertFalse(app(AdminStepUpService::class)->hasActiveGrant($admin));
        $this->assertTrue(AdminMfaSession::markerValid($admin->fresh()));
    }

    public function test_wrong_code_never_promotes_a_replacement(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);
        $oldVersion = $this->totp()->credentialFor($admin)->version();

        Livewire::actingAs($admin)->test(AdminSecurityPage::class)
            ->callAction('startReplacement', [
                'current_password' => 'secret123',
                'totp_code' => $this->currentAdminTotpCode($admin),
            ])
            ->set('replacement_code', '000000')
            ->call('confirmReplacement')
            ->assertSet('replacing', true);

        $this->assertSame($oldVersion, $this->totp()->credentialFor($admin)->version());
    }

    // ── Emergency CLI reset ──────────────────────────────────────────────────

    public function test_cli_reset_clears_the_factor_and_forces_re_enrollment(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        $secret = $this->totp()->credentialFor($admin)->secret;

        $this->artisan('zedproxy:admin-2fa-reset', ['username' => $admin->username, '--force' => true])
            ->expectsOutputToContain('cleared')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);

        $this->assertNull($this->totp()->credentialFor($admin));
        $this->assertFalse($this->totp()->hasConfirmedCredential($admin));

        // Any session marker minted against the old credential is now dead.
        $this->assertFalse(AdminMfaSession::markerValid($admin->fresh()));
    }

    public function test_cli_reset_refuses_missing_users_and_non_admins(): void
    {
        $this->artisan('zedproxy:admin-2fa-reset', ['username' => 'ghost', '--force' => true])
            ->assertExitCode(1);

        $user = User::factory()->create(['username' => 'notadmin', 'is_admin' => false]);
        $this->artisan('zedproxy:admin-2fa-reset', ['username' => 'notadmin', '--force' => true])
            ->assertExitCode(1);
        $this->assertNull($this->totp()->credentialFor($user));
    }

    public function test_cli_reset_requires_confirmation_without_force(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);

        $this->artisan('zedproxy:admin-2fa-reset', ['username' => $admin->username])
            ->expectsConfirmation("Reset admin 2FA for '{$admin->username}'? Their authenticator and recovery codes stop working immediately and enrollment is forced at next login.", 'no')
            ->assertExitCode(1);

        $this->assertTrue($this->totp()->hasConfirmedCredential($admin), 'declined confirmation must change nothing');
    }
}
