<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The successful-transport-test proof: required mode may only be enabled when
 * a recent successful test exists for the CURRENT (fingerprint-matched)
 * configuration, the proof stores no secrets, and configuration drift makes
 * both the proof and already-enabled required mode fail safe.
 */
class EmailMailTestProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('email_verification_enabled', 'true');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    private function svc(): EmailVerificationService
    {
        return app(EmailVerificationService::class);
    }

    public function test_shape_valid_smtp_without_a_successful_test_cannot_enable_required_mode(): void
    {
        // Looks usable (host+port present) — but nothing proves a server is
        // listening there, exactly the default-localhost trap.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 2525,
        ]);
        $this->assertTrue($this->svc()->isMailConfigured());
        $this->assertFalse($this->svc()->hasVerifiedMailTest());

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertFalse(
            filter_var(SiteSetting::get('email_verification_required_on_register', false), FILTER_VALIDATE_BOOLEAN),
            'required mode must be refused without a successful transport test',
        );
    }

    public function test_successful_dedicated_test_stores_proof_and_permits_required_mode(): void
    {
        Mail::fake();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertNotSame('', (string) SiteSetting::get('email_mail_test_fingerprint', ''));
        $this->assertNotNull($this->svc()->mailTestVerifiedAt());
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertTrue(
            filter_var(SiteSetting::get('email_verification_required_on_register', false), FILTER_VALIDATE_BOOLEAN),
            'a valid proof permits required mode',
        );
    }

    public function test_failed_test_does_not_store_proof(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('connection refused'));

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertSame('', (string) SiteSetting::get('email_mail_test_fingerprint', ''));
        $this->assertFalse($this->svc()->hasVerifiedMailTest());
    }

    public function test_every_operational_config_change_invalidates_the_proof(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.encryption' => 'tls',
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        $drifts = [
            ['mail.default' => 'sendmail'],
            ['mail.mailers.smtp.host' => 'other.example.com'],
            ['mail.mailers.smtp.port' => 465],
            ['mail.mailers.smtp.scheme' => 'smtps'],
            ['mail.from.address' => 'other-from@example.com'],
        ];

        foreach ($drifts as $drift) {
            $original = [];
            foreach ($drift as $key => $value) {
                $original[$key] = config($key);
                config([$key => $value]);
            }

            $this->assertFalse(
                $this->svc()->hasVerifiedMailTest(),
                'drift must invalidate the proof: '.json_encode($drift),
            );

            foreach ($original as $key => $value) {
                config([$key => $value]);
            }
            $this->assertTrue($this->svc()->hasVerifiedMailTest(), 'restored config re-validates');
        }
    }

    public function test_changing_only_the_password_neither_invalidates_nor_stores_the_secret(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'mailer-user',
            'mail.mailers.smtp.password' => 'OldSecret111',
        ]);
        $this->svc()->recordSuccessfulMailTest();

        config(['mail.mailers.smtp.password' => 'NewSecret222']);
        $this->assertTrue($this->svc()->hasVerifiedMailTest(), 'secrets are not part of the fingerprint');

        // No secret (or username value) may appear in ANY stored setting.
        foreach (DB::table('site_settings')->pluck('value') as $value) {
            $this->assertStringNotContainsString('OldSecret111', (string) $value);
            $this->assertStringNotContainsString('NewSecret222', (string) $value);
            $this->assertStringNotContainsString('mailer-user', (string) $value);
        }
    }

    public function test_expired_proof_blocks_enabling_required_mode(): void
    {
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        $this->travel(EmailVerificationService::MAIL_TEST_PROOF_MAX_DAYS + 1)->days();

        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'proof expires');

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertFalse(
            filter_var(SiteSetting::get('email_verification_required_on_register', false), FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function test_optional_mode_keeps_working_without_any_proof(): void
    {
        Mail::fake();
        SiteSetting::set('email_verification_required_on_register', 'false');
        $user = User::factory()->create(['email_verified_at' => null]);

        $result = $this->svc()->requestCode($user);

        $this->assertSame('queued', $result['status'], 'optional sending never needs a proof');
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_already_required_mode_fails_safe_after_configuration_drift(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'true');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->isRequiredOnRegister());

        // The operational configuration drifts (a new SMTP host) without a
        // fresh successful test.
        config(['mail.mailers.smtp.host' => 'brand-new-host.example.com']);

        $this->assertFalse(
            $this->svc()->isRequiredOnRegister(),
            'runtime enforcement becomes inert on drift — new users are never locked out',
        );
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
