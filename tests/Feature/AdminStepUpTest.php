<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Filament\Pages\SmsSettingsPage;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AdminMfa\AdminMfaSession;
use App\Services\AdminMfa\AdminStepUpService;
use App\Services\AdminMfa\AdminTotpService;
use App\Services\Sms\SmsService;
use App\Support\SmsFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Layer B — sensitive-settings step-up (`admin_sensitive_communications`):
 * pre-hydration locking, per-action server-side assertions, grant lifetime and
 * invalidation, and the in-scope SMS secret-exposure hardening.
 */
class AdminStepUpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    private function stepUp(): AdminStepUpService
    {
        return app(AdminStepUpService::class);
    }

    private function totp(): AdminTotpService
    {
        return app(AdminTotpService::class);
    }

    // ── Page access is locked before step-up ─────────────────────────────────

    public function test_mfa_authenticated_admin_still_gets_locked_email_settings_without_step_up(): void
    {
        $admin = $this->admin();

        // actingAs primes login MFA but NOT the step-up grant.
        $response = $this->actingAs($admin)->get('/zed-admin/settings/email');

        $response->assertOk();
        $response->assertSee('تایید دومرحله‌ای برای تنظیمات حساس');
        $response->assertDontSee('ذخیره تنظیمات');
    }

    public function test_mfa_authenticated_admin_still_gets_locked_sms_settings_without_step_up(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/zed-admin/settings/sms');

        $response->assertOk();
        $response->assertSee('تایید دومرحله‌ای برای تنظیمات حساس');
        $response->assertDontSee('ذخیره تنظیمات');
    }

    public function test_settings_are_not_hydrated_or_snapshotted_before_step_up(): void
    {
        $admin = $this->admin();
        SiteSetting::set('sms_sender', 'CANARY-SENDER-98765');
        SiteSetting::set('sms_custom_headers', '{"Authorization": "Bearer CANARY-HEADER-4321"}');

        $response = $this->actingAs($admin)->get('/zed-admin/settings/sms');
        $response->assertOk();
        $response->assertDontSee('CANARY-SENDER-98765');
        $response->assertDontSee('CANARY-HEADER-4321');

        Livewire::actingAs($admin)
            ->test(SmsSettingsPage::class)
            ->assertSet('stepUpUnlocked', false)
            ->assertSet('data', []);
    }

    // ── Grant issuance ───────────────────────────────────────────────────────

    public function test_login_step_totp_cannot_be_reused_for_step_up(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin));
        $this->be($admin);

        $code = $this->currentAdminTotpCode($admin);

        // The code is consumed at login…
        $this->assertNotNull($this->totp()->verifyAndConsume($admin, $code));

        // …so the SAME time-step can never unlock the sensitive scope.
        $this->assertFalse($this->stepUp()->attemptStepUp($admin, $code));
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin));
    }

    public function test_a_fresh_totp_unlocks_the_shared_communications_scope_for_both_pages(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin));
        $this->be($admin);

        $this->assertTrue($this->stepUp()->attemptStepUp($admin, $this->currentAdminTotpCode($admin)));

        // ONE scope covers navigation between BOTH settings pages.
        Livewire::actingAs($admin)->test(EmailSettingsPage::class)->assertSet('stepUpUnlocked', true);
        Livewire::actingAs($admin)->test(SmsSettingsPage::class)->assertSet('stepUpUnlocked', true);
        $this->assertGreaterThan(0, $this->stepUp()->remainingSeconds($admin));
        $this->assertLessThanOrEqual(AdminStepUpService::LIFETIME_MINUTES * 60, $this->stepUp()->remainingSeconds($admin));
    }

    public function test_recovery_codes_never_satisfy_step_up(): void
    {
        $admin = $this->admin();
        $enrollment = $this->totp()->startEnrollment($admin);
        $codes = $this->totp()->confirmEnrollment($admin, app(Google2FA::class)->getCurrentOtp($enrollment['secret']))['codes'];
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin));
        $this->be($admin);

        // A recovery code is not a TOTP and is refused outright.
        $this->assertFalse($this->stepUp()->attemptStepUp($admin, $codes[0]));

        // A session ENTERED via recovery cannot step up even with a live TOTP.
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin, 'recovery'));
        $cred = $this->totp()->credentialFor($admin);
        $cred->forceFill(['last_verified_timestep' => max(0, (int) $cred->last_verified_timestep - 10)])->save();
        $this->assertFalse($this->stepUp()->attemptStepUp($admin, $this->currentAdminTotpCode($admin)));
    }

    // ── Grant lifetime + invalidation ────────────────────────────────────────

    public function test_grant_expires_after_at_most_five_minutes(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);
        $this->be($admin);

        $this->assertTrue($this->stepUp()->hasActiveGrant($admin));

        $this->travel(AdminStepUpService::LIFETIME_MINUTES + 1)->minutes();

        $this->assertFalse($this->stepUp()->hasActiveGrant($admin));
    }

    public function test_grant_dies_on_password_change_demotion_totp_reset_and_replacement(): void
    {
        // Password change.
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin)->be($admin);
        $this->assertTrue($this->stepUp()->hasActiveGrant($admin));
        $admin->forceFill(['password' => bcrypt('brand-new-password')])->save();
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin->fresh()));

        // Privilege loss.
        $admin2 = $this->admin();
        $this->grantCommunicationsStepUp($admin2)->be($admin2);
        $admin2->forceFill(['is_admin' => false])->save();
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin2->fresh()));

        // TOTP reset.
        $admin3 = $this->admin();
        $this->grantCommunicationsStepUp($admin3)->be($admin3);
        $this->totp()->resetFor($admin3);
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin3));

        // Credential replacement (version rotation).
        $admin4 = $this->admin();
        $this->grantCommunicationsStepUp($admin4)->be($admin4);
        $this->travel(1)->minutes();
        $replacement = $this->totp()->startEnrollment($admin4);
        $this->assertNotNull($this->totp()->confirmEnrollment($admin4, app(Google2FA::class)->getCurrentOtp($replacement['secret'])));
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin4));
    }

    public function test_grant_is_bound_to_the_session_id(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin)->be($admin);
        $this->assertTrue($this->stepUp()->hasActiveGrant($admin));

        // An unexpected session-id change (fixation/rotation) kills the grant.
        session()->setId(str_repeat('a', 40));
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin));
    }

    public function test_lock_now_action_clears_the_grant_immediately(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->assertSet('stepUpUnlocked', true)
            ->call('lockSensitiveSettingsNow');

        $this->assertFalse($this->stepUp()->hasActiveGrant($admin));
    }

    // ── Actions re-assert server-side (no side effects when denied) ──────────

    public function test_expired_grant_blocks_email_save_with_no_mutation(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);
        SiteSetting::set('email_verification_enabled', 'false');

        $page = Livewire::actingAs($admin)->test(EmailSettingsPage::class)
            ->fillForm(['email_verification_enabled' => true]);

        $this->travel(AdminStepUpService::LIFETIME_MINUTES + 1)->minutes();

        $page->call('save');

        $this->assertFalse(
            filter_var(SiteSetting::get('email_verification_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'expired grant must not mutate settings'
        );
    }

    public function test_expired_grant_blocks_sms_save_and_clears_the_transient_key(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);
        SiteSetting::set('sms_enabled', 'false');
        SiteSetting::set('sms_api_key', '');

        $page = Livewire::actingAs($admin)->test(SmsSettingsPage::class)
            ->fillForm([
                'sms_enabled' => true,
                'sms_provider' => 'kavenegar',
                'sms_api_key_new' => 'SECRET-KEY-CANARY-777',
            ]);

        $this->travel(AdminStepUpService::LIFETIME_MINUTES + 1)->minutes();

        $page->call('save')
            ->assertSet('data.sms_api_key_new', null); // transient secret dropped

        $this->assertFalse(filter_var(SiteSetting::get('sms_enabled', false), FILTER_VALIDATE_BOOLEAN));
        $this->assertSame('', (string) SiteSetting::get('sms_api_key', ''), 'no credential may be stored on a denied action');
    }

    public function test_expired_grant_blocks_test_sends_with_no_outbound_request_or_queue(): void
    {
        Mail::fake();
        Queue::fake();
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);

        $page = Livewire::actingAs($admin)->test(EmailSettingsPage::class);

        $this->travel(AdminStepUpService::LIFETIME_MINUTES + 1)->minutes();

        $page->callAction('testEmail', ['test_email' => 'probe@example.com']);

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_direct_livewire_property_forgery_cannot_bypass_the_server_side_check(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin));
        SiteSetting::set('email_verification_enabled', 'false');

        // Forge the PRESENTATION flag (crafted Livewire update) — no grant
        // exists server-side, so save still refuses.
        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->set('stepUpUnlocked', true)
            ->fillForm(['email_verification_enabled' => true])
            ->call('save');

        $this->assertFalse(filter_var(SiteSetting::get('email_verification_enabled', false), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_unlock_component_action_issues_a_real_grant_and_relocks_on_demand(): void
    {
        $admin = $this->admin();
        $this->provisionConfirmedAdminTotp($admin);
        session()->put(AdminMfaSession::MARKER_KEY, $this->adminMfaMarker($admin));

        Livewire::actingAs($admin)
            ->test(SmsSettingsPage::class)
            ->assertSet('stepUpUnlocked', false)
            ->set('step_up_code', $this->currentAdminTotpCode($admin))
            ->call('unlockSensitiveSettings')
            ->assertRedirect('/zed-admin/settings/sms');

        $this->assertTrue($this->stepUp()->hasActiveGrant($admin));

        // Wrong code issues nothing.
        AdminStepUpService::clearGrant();
        Livewire::actingAs($admin)
            ->test(SmsSettingsPage::class)
            ->set('step_up_code', '000000')
            ->call('unlockSensitiveSettings')
            ->assertNoRedirect();
        $this->assertFalse($this->stepUp()->hasActiveGrant($admin));
    }

    // ── In-scope SMS secret-exposure hardening ───────────────────────────────

    public function test_sms_test_failure_shows_category_only_never_raw_provider_text(): void
    {
        $admin = $this->admin();
        $this->grantCommunicationsStepUp($admin);

        $throwing = new class extends SmsService
        {
            public function sendTest(string $normalizedPhone, string $message): bool
            {
                throw new \RuntimeException('401 Unauthorized — Bearer hunter2-secret-token rejected at https://api.example/send?apikey=hunter2');
            }
        };
        $this->app->instance(SmsService::class, $throwing);

        Livewire::actingAs($admin)
            ->test(SmsSettingsPage::class)
            ->callAction('testSms', ['test_phone' => '09123456789'])
            ->assertDontSee('hunter2');

        // Nothing raw may survive anywhere in the session either.
        $this->assertStringNotContainsString('hunter2', json_encode(session()->all()));
    }

    public function test_sms_failure_sanitizer_reduces_to_category_and_masks_detail(): void
    {
        $e = new \RuntimeException('401 Unauthorized — Bearer hunter2 rejected');

        $this->assertSame('auth_failed', SmsFailure::categorize($e));

        $timeout = new \RuntimeException('cURL error 28: Operation timed out after 10000 ms');
        $this->assertSame('timeout', SmsFailure::categorize($timeout));

        $summary = SmsFailure::summarize('test sms failed', $e);
        $this->assertStringContainsString('auth_failed', $summary);
        $this->assertLessThanOrEqual(SmsFailure::MAX_LENGTH, mb_strlen($summary));
    }

    public function test_legacy_plaintext_sms_key_is_migrated_and_corrupt_ciphertext_fails_closed(): void
    {
        // Legacy plaintext: accepted once, then re-encrypted in place.
        SiteSetting::set('sms_api_key', 'legacy-plain-key-123');
        $svc = app(SmsService::class);
        $this->assertSame('legacy-plain-key-123', $svc->apiKey());

        $stored = (string) SiteSetting::get('sms_api_key');
        $this->assertNotSame('legacy-plain-key-123', $stored, 'plaintext must be re-encrypted after first read');
        $this->assertSame('legacy-plain-key-123', Crypt::decryptString($stored));

        // A value that structurally IS a ciphertext but cannot decrypt
        // (APP_KEY change / corruption) is treated as UNSET — never returned.
        $corrupt = base64_encode(json_encode(['iv' => base64_encode(random_bytes(16)), 'value' => base64_encode(random_bytes(32)), 'mac' => str_repeat('ab', 32)]));
        SiteSetting::set('sms_api_key', $corrupt);
        $this->assertSame('', $svc->apiKey());
    }
}
