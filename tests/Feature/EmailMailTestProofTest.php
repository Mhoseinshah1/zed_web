<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Models\EmailVerificationCode;
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
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        // The settings page now sits behind the communications step-up; the
        // tests here exercise the mail-proof logic itself, so pre-grant it.
        $this->grantCommunicationsStepUp($admin);

        return $admin;
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
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('connection refused'));

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertSame('', (string) SiteSetting::get('email_mail_test_fingerprint', ''));
        $this->assertFalse($this->svc()->hasVerifiedMailTest());
    }

    public function test_a_failed_transport_test_revokes_the_existing_proof(): void
    {
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        // The endpoint died since the earlier success: the failed
        // certification is NEGATIVE evidence — the historical proof must not
        // keep required mode armed until three OTP jobs finish failing.
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('connection refused'));
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'a failed endpoint test revokes the proof');
    }

    public function test_a_recipient_specific_test_bounce_keeps_the_proof(): void
    {
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        // The admin typo'd the destination: a PROVEN mailbox-specific bounce
        // says nothing about the endpoint — the proof survives. (A relay
        // denial phrased as a "recipient" rejection would NOT qualify.)
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('550 5.1.1 user unknown: no such user here'));
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'typo@example.com']);

        $this->assertTrue($this->svc()->hasVerifiedMailTest(), 'a recipient bounce never revokes the proof');
    }

    public function test_a_relay_denial_phrased_as_recipient_rejection_revokes_the_proof(): void
    {
        $this->svc()->recordSuccessfulMailTest();

        // "Recipient address rejected: Relay access denied" is SENDER-side
        // policy — every destination fails, so it is endpoint evidence, not a
        // mailbox bounce: the proof must be revoked.
        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('554 Recipient address rejected: Relay access denied'));
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'relay denial is endpoint evidence');
    }

    public function test_changing_the_smtp_timeout_invalidates_the_proof(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.timeout' => 10,
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        // Shrinking the per-operation timeout can make real OTP sends time
        // out where the certification succeeded — the proof must not survive.
        config(['mail.mailers.smtp.timeout' => 1]);
        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'an operational timeout change demands a fresh test');
    }

    public function test_multi_leaf_composites_are_rejected_for_otp_delivery(): void
    {
        $svc = $this->svc();
        config([
            'mail.default' => 'combo',
            'mail.mailers.combo' => ['transport' => 'roundrobin', 'mailers' => ['smtp_a', 'smtp_b']],
            'mail.mailers.smtp_a' => ['transport' => 'smtp', 'host' => 'a.example.com', 'port' => 587],
            'mail.mailers.smtp_b' => ['transport' => 'smtp', 'host' => 'b.example.com', 'port' => 587],
        ]);

        // The delivery time budget (job timeout 240s = claim margin < lock
        // TTL 270s < redelivery horizon 300s) covers exactly ONE complete
        // transport exchange — every multi-leaf routing policy is rejected,
        // never silently reduced to its first child.
        $this->assertFalse($svc->isMailConfigured(), 'two roundrobin leaves exceed the single-exchange budget');

        config(['mail.mailers.combo.transport' => 'failover']);
        $this->assertFalse($svc->isMailConfigured(), 'failover smtp+smtp is rejected');

        config([
            'mail.default' => 'outer',
            'mail.mailers.outer' => ['transport' => 'failover', 'mailers' => ['combo']],
        ]);
        $this->assertFalse($svc->isMailConfigured(), 'nesting cannot launder a multi-leaf graph');

        // The certification action refuses: no mail is sent, no proof stored.
        config(['mail.default' => 'combo']);
        Mail::fake();
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);
        Mail::assertNothingSent();
        $this->assertFalse($svc->hasVerifiedMailTest(), 'an unsafe composite earns no proof');

        // No OTP row and no queued job may ever be created through it.
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->assertSame('error', $svc->requestCode($user)['status']);
        $this->assertSame(0, EmailVerificationCode::query()->count(), 'no issuance under an unsafe timing graph');
    }

    public function test_a_composite_resolving_to_one_leaf_is_accepted_and_graph_growth_revokes_it(): void
    {
        $svc = $this->svc();
        config([
            'mail.default' => 'solo',
            'mail.mailers.solo' => ['transport' => 'failover', 'mailers' => ['smtp_a']],
            'mail.mailers.smtp_a' => ['transport' => 'smtp', 'host' => 'a.example.com', 'port' => 587],
            'mail.mailers.smtp_b' => ['transport' => 'smtp', 'host' => 'b.example.com', 'port' => 587],
        ]);

        // DOCUMENTED CHOICE: a composite that RESOLVES to exactly one leaf
        // performs at most one exchange — accepted.
        $this->assertTrue($svc->isMailConfigured(), 'a single-leaf composite is inside the time budget');

        $svc->recordSuccessfulMailTest();
        $this->assertTrue($svc->hasVerifiedMailTest());

        // Growing the certified single-leaf graph into a multi-leaf one
        // both invalidates the stored proof (fingerprint/topology) and
        // rejects the configuration outright.
        config(['mail.mailers.solo.mailers' => ['smtp_a', 'smtp_b']]);
        $this->assertFalse($svc->hasVerifiedMailTest(), 'the single-leaf proof does not survive graph growth');
        $this->assertFalse($svc->isMailConfigured());
    }

    public function test_required_mode_cannot_be_enabled_with_an_unsafe_composite(): void
    {
        config([
            'mail.default' => 'combo',
            'mail.mailers.combo' => ['transport' => 'failover', 'mailers' => ['smtp_a', 'smtp_b']],
            'mail.mailers.smtp_a' => ['transport' => 'smtp', 'host' => 'a.example.com', 'port' => 587],
            'mail.mailers.smtp_b' => ['transport' => 'smtp', 'host' => 'b.example.com', 'port' => 587],
        ]);
        // Even with a (stale) proof present, the save guard refuses.
        $this->svc()->recordSuccessfulMailTest();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertFalse(
            (bool) SiteSetting::get('email_verification_required_on_register', false),
            'required mode is refused for a multi-leaf graph',
        );
    }

    public function test_composite_topology_changes_invalidate_the_proof(): void
    {
        config([
            'mail.default' => 'combo',
            'mail.mailers.combo' => ['transport' => 'failover', 'mailers' => ['smtp_a', 'smtp_b']],
            'mail.mailers.smtp_a' => ['transport' => 'smtp', 'host' => 'a.example.com', 'port' => 587],
            'mail.mailers.smtp_b' => ['transport' => 'smtp', 'host' => 'b.example.com', 'port' => 587],
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        // Same leaves, different ROUTING POLICY: the flattened leaf map is
        // identical, so only the topology component can catch this.
        config(['mail.mailers.combo.transport' => 'roundrobin']);
        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'failover→roundrobin invalidates the proof');

        config(['mail.mailers.combo.transport' => 'failover']);
        $this->assertTrue($this->svc()->hasVerifiedMailTest(), 'restoring the tested policy restores the proof');

        // Same leaves, different child ORDER (primary swapped).
        config(['mail.mailers.combo.mailers' => ['smtp_b', 'smtp_a']]);
        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'child reorder invalidates the proof');
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
            ['mail.mailers.smtp.local_domain' => 'new-ehlo.example.com'],
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

    public function test_every_credential_rotation_invalidates_the_proof_without_storing_secrets(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'mailer-user',
            'mail.mailers.smtp.password' => 'OldSecret111',
            'mail.mailers.smtp.url' => null,
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        // Rotating ANY credential the effective transport uses invalidates
        // the proof; restoring the original value revalidates it (same
        // APP_KEY): the keyed digest is deterministic but non-reversible.
        $drifts = [
            'mail.mailers.smtp.password' => 'NewSecret222',
            'mail.mailers.smtp.username' => 'other-user',
            'mail.mailers.smtp.url' => 'smtp://mailer-user:UrlSecret333@mail.example.com:587',
        ];
        foreach ($drifts as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            $this->assertFalse($this->svc()->hasVerifiedMailTest(), "credential drift must invalidate: {$key}");
            config([$key => $original]);
            $this->assertTrue($this->svc()->hasVerifiedMailTest(), "restored credential revalidates: {$key}");
        }

        // No secret (or username value) may appear in ANY stored setting, and
        // the persisted fingerprint is an opaque hash with no recognizable
        // credential fragments.
        $fingerprint = (string) SiteSetting::get('email_mail_test_fingerprint', '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        foreach (DB::table('site_settings')->pluck('value') as $value) {
            foreach (['OldSecret111', 'NewSecret222', 'UrlSecret333', 'mailer-user', 'other-user'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $value);
            }
        }

        // And nothing leaked into the application log either.
        $log = storage_path('logs/laravel.log');
        $contents = file_exists($log) ? (string) file_get_contents($log) : '';
        $this->assertStringNotContainsString('OldSecret111', $contents);
        $this->assertStringNotContainsString('NewSecret222', $contents);
    }

    public function test_api_transport_credential_rotations_invalidate_the_proof(): void
    {
        $cases = [
            ['mail.default' => 'ses', 'secret' => 'services.ses.secret'],
            ['mail.default' => 'ses', 'secret' => 'services.ses.key'],
            ['mail.default' => 'postmark', 'secret' => 'services.postmark.key'],
            ['mail.default' => 'resend', 'secret' => 'services.resend.key'],
        ];

        foreach ($cases as $case) {
            config(['mail.default' => $case['mail.default'], $case['secret'] => 'original-credential-1']);
            $this->svc()->recordSuccessfulMailTest();
            $this->assertTrue($this->svc()->hasVerifiedMailTest(), $case['secret'].' baseline');

            config([$case['secret'] => 'rotated-credential-2']);
            $this->assertFalse($this->svc()->hasVerifiedMailTest(), $case['secret'].' rotation must invalidate');
        }

        // Mailgun (mailer defined ad hoc — services.mailgun holds the secret).
        config([
            'mail.default' => 'mailgun',
            'mail.mailers.mailgun' => ['transport' => 'mailgun'],
            'services.mailgun.secret' => 'mg-original', 'services.mailgun.domain' => 'mg.example.com',
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());
        config(['services.mailgun.secret' => 'mg-rotated']);
        $this->assertFalse($this->svc()->hasVerifiedMailTest(), 'Mailgun secret rotation must invalidate');
    }

    public function test_app_key_rotation_invalidates_the_proof(): void
    {
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->hasVerifiedMailTest());

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertFalse(
            $this->svc()->hasVerifiedMailTest(),
            'the digest is keyed by an APP_KEY-derived key — rotation invalidates old proofs',
        );
    }

    public function test_required_mode_fails_safe_after_credential_drift(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'true');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.password' => 'RotateMe111',
        ]);
        $this->svc()->recordSuccessfulMailTest();
        $this->assertTrue($this->svc()->isRequiredOnRegister());

        config(['mail.mailers.smtp.password' => 'RotatedAway222']);

        $this->assertFalse($this->svc()->isRequiredOnRegister(), 'credential drift degrades required mode');
        // Even a user OBLIGATED at registration passes during the fail-safe.
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->forceFill(['email_verification_required_at_registration' => true])->save();
        $this->actingAs($user)->get('/dashboard')->assertOk();
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
        // Even a user OBLIGATED at registration passes during the fail-safe.
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->forceFill(['email_verification_required_at_registration' => true])->save();
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
