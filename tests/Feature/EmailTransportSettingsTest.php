<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Models\EmailTransportSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailTransportSettingsService;
use App\Services\Email\EmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/** Minimal queue probe — its processing fires the Queue::before hook. */
class TransportRefreshProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}

/**
 * Admin-managed SMTP configuration: encrypted-at-rest storage, runtime
 * application through the dedicated managed_smtp mailer (web + long-running
 * workers), fail-closed semantics, UI secret safety, and one shared
 * transport-test proof policy across the environment and panel sources.
 */
class EmailTransportSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY_USER = 'CANARY-SMTP-USER-31337';

    private const CANARY_PASS = 'CANARY-SMTP-PASS-secret-73313';

    private function svc(): EmailTransportSettingsService
    {
        return app(EmailTransportSettingsService::class);
    }

    private function verification(): EmailVerificationService
    {
        return app(EmailVerificationService::class);
    }

    private function admin(bool $withStepUp = true): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        if ($withStepUp) {
            $this->grantCommunicationsStepUp($admin);
        }

        return $admin;
    }

    /** A structurally valid, enabled panel row with canary credentials. */
    private function enabledRow(array $overrides = []): EmailTransportSetting
    {
        $row = EmailTransportSetting::instanceOrNew();
        $row->fill(array_merge([
            'enabled' => true,
            'host' => 'smtp.panel.example',
            'port' => 587,
            'security' => 'smtp',
            'from_address' => 'panel@example.com',
            'from_name' => 'Panel Sender',
            'timeout' => 10,
            'local_domain' => 'panel.example',
        ], $overrides));
        $row->setUsernameSecret(self::CANARY_USER);
        $row->setPasswordSecret(self::CANARY_PASS);
        $row->save();

        return $row;
    }

    /** Valid form state matching enabledRow() (password left blank). */
    private function formState(array $overrides = []): array
    {
        return array_merge([
            'smtp_override_enabled' => true,
            'smtp_host' => 'smtp.panel.example',
            'smtp_port' => 587,
            'smtp_security' => 'smtp',
            'smtp_username' => self::CANARY_USER,
            'smtp_password_new' => null,
            'smtp_from_address' => 'panel@example.com',
            'smtp_from_name' => 'Panel Sender',
            'smtp_timeout' => 10,
            'smtp_local_domain' => 'panel.example',
        ], $overrides);
    }

    // ── 4.1 Encrypted storage ───────────────────────────────────────────────

    public function test_secrets_are_encrypted_at_rest_with_no_plaintext_canaries(): void
    {
        $this->enabledRow();

        $raw = DB::table('email_transport_settings')->first();

        $this->assertNotNull($raw->username);
        $this->assertNotNull($raw->password);
        $rawDump = json_encode($raw);
        $this->assertStringNotContainsString(self::CANARY_USER, $rawDump, 'raw DB row must not contain the username canary');
        $this->assertStringNotContainsString(self::CANARY_PASS, $rawDump, 'raw DB row must not contain the password canary');

        // The authenticated cast round-trips the plaintext in memory only.
        $fresh = EmailTransportSetting::instance();
        $this->assertSame(self::CANARY_USER, $fresh->username);
        $this->assertSame(self::CANARY_PASS, $fresh->password);
    }

    public function test_serialization_hides_username_and_password(): void
    {
        $row = $this->enabledRow();

        $serialized = json_encode([$row->toArray(), $row->toJson(), $row->fresh()->toArray()]);

        $this->assertArrayNotHasKey('username', $row->toArray());
        $this->assertArrayNotHasKey('password', $row->toArray());
        $this->assertStringNotContainsString(self::CANARY_USER, $serialized);
        $this->assertStringNotContainsString(self::CANARY_PASS, $serialized);
    }

    public function test_secrets_are_not_mass_assignable(): void
    {
        $row = new EmailTransportSetting([
            'enabled' => true,
            'username' => 'mass-assigned-user',
            'password' => 'mass-assigned-pass',
        ]);

        $this->assertNull($row->getRawOriginal('username'));
        $this->assertArrayNotHasKey('username', $row->getAttributes());
        $this->assertArrayNotHasKey('password', $row->getAttributes());

        $existing = $this->enabledRow();
        $existing->fill(['password' => 'overwritten-by-fill']);
        $existing->save();
        $this->assertSame(self::CANARY_PASS, $existing->fresh()->password, 'fill() must never reach the password');
    }

    public function test_pre_migration_bootstrap_uses_environment_fallback(): void
    {
        $defaultBefore = config('mail.default');

        Schema::drop('email_transport_settings');

        $this->assertNull(EmailTransportSetting::instance());
        $this->assertSame(EmailTransportSettingsService::SOURCE_ENV, $this->svc()->effectiveSource());

        $this->svc()->apply();
        $this->assertSame($defaultBefore, config('mail.default'), 'missing table must leave the env config authoritative');
    }

    public function test_corrupt_active_credentials_fail_closed_never_env_fallback(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'env.example', 'mail.mailers.smtp.port' => 587]);
        $this->enabledRow();

        // Simulate APP_KEY rotation / ciphertext corruption on the ACTIVE row.
        DB::table('email_transport_settings')->update(['password' => 'not-decryptable-ciphertext']);

        $this->assertSame(EmailTransportSettingsService::SOURCE_PANEL_INVALID, $this->svc()->effectiveSource());

        $this->svc()->apply();

        // Fail closed: the default is the deliberately unusable managed
        // mailer — NOT the environment smtp transport.
        $this->assertSame('managed_smtp', config('mail.default'));
        $this->assertSame('', config('mail.mailers.managed_smtp.host'));
        $this->assertFalse($this->verification()->isMailConfigured());
        // The env-backed smtp mailer definition itself is untouched.
        $this->assertSame('env.example', config('mail.mailers.smtp.host'));
    }

    // ── 4.2 Runtime application ─────────────────────────────────────────────

    public function test_enabled_valid_override_becomes_the_default_managed_mailer(): void
    {
        $envSmtp = config('mail.mailers.smtp');
        $this->enabledRow();

        $this->svc()->apply();

        $this->assertSame('managed_smtp', config('mail.default'));
        $this->assertSame('smtp.panel.example', config('mail.mailers.managed_smtp.host'));
        $this->assertSame(587, config('mail.mailers.managed_smtp.port'));
        $this->assertSame('smtp', config('mail.mailers.managed_smtp.scheme'));
        $this->assertSame(10, config('mail.mailers.managed_smtp.timeout'));
        $this->assertSame('panel.example', config('mail.mailers.managed_smtp.local_domain'));
        $this->assertSame('panel@example.com', config('mail.from.address'));
        $this->assertSame('Panel Sender', config('mail.from.name'));

        // The environment-backed smtp mailer is NEVER mutated.
        $this->assertSame($envSmtp, config('mail.mailers.smtp'));

        // Single delivery leaf; usable per the shared verification service.
        $this->assertSame(['managed_smtp' => 'smtp'], $this->verification()->effectiveLeafMailers());
        $this->assertTrue($this->verification()->isMailConfigured());
    }

    public function test_disabled_override_keeps_environment_configuration(): void
    {
        $defaultBefore = config('mail.default');
        $fromBefore = config('mail.from.address');

        $this->enabledRow(['enabled' => false]);
        $this->svc()->apply();

        $this->assertSame(EmailTransportSettingsService::SOURCE_ENV, $this->svc()->effectiveSource());
        $this->assertSame($defaultBefore, config('mail.default'));
        $this->assertSame($fromBefore, config('mail.from.address'));
    }

    public function test_disabling_after_enabling_restores_env_identity_within_the_process(): void
    {
        $defaultBefore = config('mail.default');
        $fromBefore = config('mail.from.address');
        $fromNameBefore = config('mail.from.name');

        $row = $this->enabledRow();
        $this->svc()->apply();
        $this->assertSame('managed_smtp', config('mail.default'));

        $row->fill(['enabled' => false])->save();
        $this->svc()->apply();

        $this->assertSame($defaultBefore, config('mail.default'));
        $this->assertSame($fromBefore, config('mail.from.address'));
        $this->assertSame($fromNameBefore, config('mail.from.name'));
    }

    /**
     * Every security option offered by the UI maps to a verified runtime
     * transport behavior: `smtp` → no implicit TLS (STARTTLS negotiation),
     * `smtps` → implicit TLS from the first byte. The mapping is proven on
     * the CONSTRUCTED Symfony transport, not just on config values.
     */
    public function test_security_options_map_to_runtime_transport_behavior(): void
    {
        foreach ([['smtp', 587, false], ['smtps', 465, true]] as [$security, $port, $implicitTls]) {
            $this->enabledRow(['security' => $security, 'port' => $port]);
            $this->svc()->apply();

            $transport = Mail::mailer('managed_smtp')->getSymfonyTransport();
            $this->assertInstanceOf(EsmtpTransport::class, $transport);
            $this->assertSame($implicitTls, $transport->getStream()->isTLS(), "scheme {$security} TLS mapping");
            $this->assertSame(10.0, $transport->getStream()->getTimeout(), 'per-operation timeout mapping');
        }
    }

    public function test_long_lived_worker_adopts_new_configuration_before_next_job(): void
    {
        // Configuration A is applied in this (worker) process…
        $row = $this->enabledRow(['host' => 'smtp.a.example']);
        $this->svc()->apply();
        $this->assertSame('smtp.a.example', config('mail.mailers.managed_smtp.host'));
        $this->assertSame(
            'smtp.a.example',
            Mail::mailer('managed_smtp')->getSymfonyTransport()->getStream()->getHost(),
        );

        // …the database changes to B behind its back…
        $row->fill(['host' => 'smtp.b.example'])->save();

        // …and processing ANY queued job (sync driver fires the exact same
        // JobProcessing → Queue::before hook a Redis worker gets) refreshes
        // the effective configuration WITHOUT a process restart.
        TransportRefreshProbeJob::dispatch();

        $this->assertSame('smtp.b.example', config('mail.mailers.managed_smtp.host'));
        $this->assertSame(
            'smtp.b.example',
            Mail::mailer('managed_smtp')->getSymfonyTransport()->getStream()->getHost(),
            'the cached mailer instance must have been purged and rebuilt from B',
        );
    }

    public function test_unchanged_configuration_causes_no_repeated_purges(): void
    {
        $this->enabledRow();
        $this->svc()->apply();

        $purgesAfterApply = $this->svc()->purgeCount();

        TransportRefreshProbeJob::dispatch();
        TransportRefreshProbeJob::dispatch();
        TransportRefreshProbeJob::dispatch();
        $this->svc()->apply();

        $this->assertSame(
            $purgesAfterApply,
            $this->svc()->purgeCount(),
            'an unchanged configuration version must never purge the cached mailer again',
        );
    }

    // ── 4.3 Protected UI ────────────────────────────────────────────────────

    public function test_locked_page_contains_no_smtp_canaries_in_html_or_snapshot(): void
    {
        $this->enabledRow(['host' => 'CANARY-HOST-55221.example']);
        $admin = $this->admin(withStepUp: false);

        $response = $this->actingAs($admin)->get('/zed-admin/settings/email');
        $response->assertOk();
        $response->assertDontSee(self::CANARY_USER);
        $response->assertDontSee(self::CANARY_PASS);
        $response->assertDontSee('CANARY-HOST-55221.example');

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->assertSet('stepUpUnlocked', false)
            ->assertSet('data', []);
    }

    public function test_unlocked_page_never_hydrates_the_stored_password(): void
    {
        $this->enabledRow();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->assertSet('stepUpUnlocked', true)
            ->assertSet('data.smtp_password_new', null)
            // Username is allowed to display AFTER step-up.
            ->assertSet('data.smtp_username', self::CANARY_USER);

        $response = $this->actingAs($admin)->get('/zed-admin/settings/email');
        $response->assertOk();
        $response->assertDontSee(self::CANARY_PASS);
    }

    public function test_blank_password_preserves_the_stored_secret(): void
    {
        $this->enabledRow();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState(['smtp_password_new' => null]))
            ->call('save')
            ->assertSet('data.smtp_password_new', null);

        $this->assertSame(self::CANARY_PASS, EmailTransportSetting::instance()->password);
        $this->assertTrue(EmailTransportSetting::instance()->hasStoredPassword());
    }

    public function test_explicit_clear_action_removes_the_stored_password(): void
    {
        $this->enabledRow();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('clearSmtpPassword');

        $this->assertFalse(EmailTransportSetting::instance()->hasStoredPassword());
        $this->assertNull(DB::table('email_transport_settings')->value('password'));
    }

    public function test_expired_step_up_blocks_smtp_mutation_and_password_clear(): void
    {
        $row = $this->enabledRow();
        $before = DB::table('email_transport_settings')->first();
        $admin = $this->admin(withStepUp: false);

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->call('save');

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->call('mountAction', 'clearSmtpPassword');

        $after = DB::table('email_transport_settings')->first();
        $this->assertEquals($before, $after, 'no SMTP mutation may happen without an active step-up grant');
        $this->assertTrue($row->fresh()->hasStoredPassword());
    }

    public function test_forged_presentation_property_grants_no_access(): void
    {
        $row = $this->enabledRow();
        $before = DB::table('email_transport_settings')->first();
        $admin = $this->admin(withStepUp: false);

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->set('stepUpUnlocked', true)
            ->call('save');

        $this->assertEquals($before, DB::table('email_transport_settings')->first());
        $this->assertSame(self::CANARY_PASS, $row->fresh()->password);
    }

    public function test_enabling_the_override_requires_structurally_valid_values(): void
    {
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState(['smtp_host' => null]))
            ->call('save')
            ->assertHasFormErrors(['smtp_host']);

        $this->assertNull(EmailTransportSetting::instance(), 'a structurally invalid enable must persist nothing');

        // The authoritative server-side rule also rejects values Filament's
        // select can no longer produce (e.g. a forged legacy 'tls').
        $row = new EmailTransportSetting([
            'enabled' => true, 'host' => 'h', 'port' => 587,
            'security' => 'tls', 'from_address' => 'a@b.c', 'timeout' => 10,
        ]);
        $this->assertFalse($this->svc()->rowLooksStructurallyValid($row));
        $this->assertTrue($this->svc()->rowLooksStructurallyValid(
            new EmailTransportSetting([
                'enabled' => true, 'host' => 'h', 'port' => 587,
                'security' => 'smtps', 'from_address' => 'a@b.c', 'timeout' => 10,
            ]),
        ));
    }

    public function test_incomplete_values_may_be_saved_while_override_stays_disabled(): void
    {
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState([
                'smtp_override_enabled' => false,
                'smtp_host' => 'smtp.draft.example',
                'smtp_port' => null,
                'smtp_security' => null,
                'smtp_from_address' => null,
                'smtp_timeout' => null,
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $row = EmailTransportSetting::instance();
        $this->assertFalse($row->enabled);
        $this->assertSame('smtp.draft.example', $row->host);
        $this->assertSame(EmailTransportSettingsService::SOURCE_ENV, $this->svc()->effectiveSource());
    }

    public function test_disabling_remains_possible_while_the_panel_configuration_is_invalid(): void
    {
        $this->enabledRow();
        // Corrupt the ACTIVE configuration outside the page.
        DB::table('email_transport_settings')->update(['password' => 'garbage', 'host' => null]);
        $this->svc()->apply();
        $this->assertSame(EmailTransportSettingsService::SOURCE_PANEL_INVALID, $this->svc()->effectiveSource());

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm(['smtp_override_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(EmailTransportSetting::instance()->enabled);
        $this->assertSame(EmailTransportSettingsService::SOURCE_ENV, $this->svc()->effectiveSource());
    }

    // ── 4.4 Proof semantics ─────────────────────────────────────────────────

    /** Record a proof valid for the CURRENT effective configuration. */
    private function recordProof(): void
    {
        $this->verification()->recordSuccessfulMailTest();
        $this->assertTrue($this->verification()->hasVerifiedMailTest());
    }

    public function test_every_semantic_field_change_rotates_the_effective_fingerprint(): void
    {
        $row = $this->enabledRow();
        $this->svc()->apply();
        $baseline = $this->verification()->mailConfigFingerprint();

        $variants = [
            'host' => fn () => $row->fill(['host' => 'other.example'])->save(),
            'port' => fn () => $row->fill(['port' => 2525])->save(),
            'security' => fn () => $row->fill(['security' => 'smtps'])->save(),
            'username' => function () use ($row) {
                $row->setUsernameSecret('other-user');
                $row->save();
            },
            'password' => function () use ($row) {
                $row->setPasswordSecret('other-pass');
                $row->save();
            },
            'from_address' => fn () => $row->fill(['from_address' => 'other@example.com'])->save(),
            'from_name' => fn () => $row->fill(['from_name' => 'Other Name'])->save(),
            'timeout' => fn () => $row->fill(['timeout' => 5])->save(),
            'local_domain' => fn () => $row->fill(['local_domain' => 'other.example'])->save(),
            'override_disabled' => fn () => $row->fill(['enabled' => false])->save(),
        ];

        $seen = [$baseline];
        foreach ($variants as $field => $mutate) {
            $mutate();
            $this->svc()->apply();
            $fingerprint = $this->verification()->mailConfigFingerprint();
            $this->assertNotContains($fingerprint, $seen, "changing {$field} must rotate the fingerprint");
            $seen[] = $fingerprint;
        }
    }

    public function test_semantic_change_via_the_page_revokes_the_proof_in_the_settings_transaction(): void
    {
        $this->enabledRow();
        $this->svc()->apply();
        $this->recordProof();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState(['smtp_host' => 'changed.example']))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('', (string) SiteSetting::get('email_mail_test_fingerprint', ''));
        $this->assertFalse($this->verification()->hasVerifiedMailTest());
    }

    public function test_identical_save_preserves_a_still_valid_proof(): void
    {
        $this->enabledRow();
        $this->svc()->apply();
        $this->recordProof();
        $storedFingerprint = (string) SiteSetting::get('email_mail_test_fingerprint', '');

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($storedFingerprint, (string) SiteSetting::get('email_mail_test_fingerprint', ''));
        $this->assertTrue($this->verification()->hasVerifiedMailTest());
    }

    public function test_retyping_the_same_password_preserves_the_proof(): void
    {
        $this->enabledRow();
        $this->svc()->apply();
        $this->recordProof();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState(['smtp_password_new' => self::CANARY_PASS]))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->verification()->hasVerifiedMailTest(), 're-encrypting an identical password is not a semantic change');
    }

    public function test_password_clear_revokes_the_proof(): void
    {
        $this->enabledRow();
        $this->svc()->apply();
        $this->recordProof();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('clearSmtpPassword');

        $this->assertFalse($this->verification()->hasVerifiedMailTest());
    }

    public function test_enabling_the_override_revokes_an_env_scoped_proof(): void
    {
        // Proof recorded while the ENVIRONMENT source is effective…
        $this->recordProof();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm($this->formState())
            ->call('save')
            ->assertHasNoFormErrors();

        // …the source switch is a semantic change: the proof must die.
        $this->assertFalse($this->verification()->hasVerifiedMailTest());
    }

    public function test_successful_test_email_certifies_the_panel_configuration(): void
    {
        Mail::fake();
        $this->enabledRow();
        $this->svc()->apply();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertTrue($this->verification()->hasVerifiedMailTest());
        // The proof is bound to the PANEL fingerprint: an env-source proof
        // would not match once the override is active.
        $this->assertSame(
            $this->verification()->mailConfigFingerprint(),
            (string) SiteSetting::get('email_mail_test_fingerprint', ''),
        );
    }

    public function test_failed_test_email_through_panel_config_records_no_proof(): void
    {
        $this->enabledRow();
        $this->svc()->apply();

        Mail::shouldReceive('mailer')->andReturnSelf();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('connection refused'));

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        $this->assertFalse($this->verification()->hasVerifiedMailTest());
    }

    public function test_proof_expires_after_the_trust_window_for_panel_source_too(): void
    {
        $this->enabledRow();
        $this->svc()->apply();
        $this->recordProof();

        $this->travel(EmailVerificationService::MAIL_TEST_PROOF_MAX_DAYS + 1)->days();

        $this->assertFalse($this->verification()->hasVerifiedMailTest());
    }

    public function test_required_mode_fails_safe_when_the_active_panel_config_breaks(): void
    {
        Mail::fake();
        $this->enabledRow();
        $this->svc()->apply();
        SiteSetting::set('email_verification_enabled', 'true');
        SiteSetting::set('email_verification_required_on_register', 'true');
        $this->recordProof();
        $this->assertTrue($this->verification()->isRequiredOnRegister());

        // The active panel row becomes undecryptable (e.g. APP_KEY rotation).
        DB::table('email_transport_settings')->update(['password' => 'garbage']);
        $this->svc()->apply();

        $this->assertFalse($this->verification()->isMailConfigured());
        $this->assertFalse($this->verification()->isRequiredOnRegister(), 'required verification must automatically become unenforceable');
    }

    // ── Immutable environment baseline ──────────────────────────────────────

    /** Route the model's storage through an unreachable database. */
    private function breakStorage(): string
    {
        $original = (string) config('database.default');
        config(['database.connections.zp_broken' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent-zp/never-here.sqlite',
            'prefix' => '',
        ]]);
        DB::setDefaultConnection('zp_broken');

        return $original;
    }

    private function restoreStorage(string $connection): void
    {
        DB::setDefaultConnection($connection);
    }

    /** Simulate a process booted under a specific environment mail config. */
    private function stageEnvironment(array $config): void
    {
        EmailTransportSettingsService::resetProcessBaselineForTesting();
        config($config);
    }

    public function test_clearing_the_panel_from_name_restores_the_environment_name_immediately_everywhere(): void
    {
        $this->stageEnvironment(['mail.from.name' => 'Env Name E']);

        // Panel name A is active, certified, and applied — both in the app
        // singleton AND in a separate long-running-worker service instance.
        $row = $this->enabledRow(['from_name' => 'Panel Name A']);
        $this->svc()->apply();
        $worker = new EmailTransportSettingsService;
        $worker->apply();
        $this->assertSame('Panel Name A', config('mail.from.name'));
        $versionA = $this->svc()->version();
        $this->recordProof();

        // Clearing the OPTIONAL panel name must restore the ORIGINAL
        // environment name — never echo the previously applied panel name
        // back out of the mutated runtime config.
        $row->fill(['from_name' => null])->save();
        $this->svc()->apply();

        $this->assertSame('Env Name E', config('mail.from.name'), 'immediate restore in this process');
        $versionE = $this->svc()->version();
        $this->assertNotSame($versionA, $versionE, 'the effective fingerprint changes from A to E');
        $this->assertFalse($this->verification()->hasVerifiedMailTest(), 'the A-scoped proof is invalidated');

        // The long-running worker (last applied A) converges before its next
        // job: re-stage its stale view, then run its pre-job refresh.
        config(['mail.from.name' => 'Panel Name A']);
        $worker->apply();
        $this->assertSame('Env Name E', config('mail.from.name'), 'worker adopts E before its next job');
        // And the Queue::before hook (singleton) yields the same result.
        TransportRefreshProbeJob::dispatch();
        $this->assertSame('Env Name E', config('mail.from.name'));

        // …and a freshly instantiated service (fresh process sharing the same
        // environment) computes the SAME effective configuration/fingerprint.
        $fresh = new EmailTransportSettingsService;
        $fresh->apply();
        $this->assertSame('Env Name E', config('mail.from.name'));
        $this->assertSame($versionE, $fresh->version(), 'fresh and long-lived processes agree on the fingerprint');
    }

    public function test_disable_restores_an_original_environment_managed_smtp_definition_exactly(): void
    {
        // A deployment whose OWN environment config defines the reserved
        // name — and even uses it as the default mailer.
        $envDefinition = ['transport' => 'smtp', 'host' => 'env-managed.example', 'port' => 2525, 'timeout' => 5];
        $this->stageEnvironment([
            'mail.mailers.managed_smtp' => $envDefinition,
            'mail.default' => 'managed_smtp',
        ]);

        $row = $this->enabledRow();
        $this->svc()->apply();
        $this->assertSame('smtp.panel.example', config('mail.mailers.managed_smtp.host'), 'panel override is active');

        $row->fill(['enabled' => false])->save();
        $this->svc()->apply();

        // The ORIGINAL definition is restored exactly — old panel credentials
        // are not retained merely because the env default carries the name.
        $this->assertSame($envDefinition, config('mail.mailers.managed_smtp'));
        $this->assertSame('managed_smtp', config('mail.default'));
        $runtime = json_encode(config('mail.mailers.managed_smtp'));
        $this->assertStringNotContainsString(self::CANARY_USER, $runtime);
        $this->assertStringNotContainsString(self::CANARY_PASS, $runtime);
        $this->assertStringNotContainsString('smtp.panel.example', $runtime);
    }

    public function test_disable_neutralizes_the_runtime_definition_when_env_had_none(): void
    {
        $row = $this->enabledRow();
        $this->svc()->apply();
        $this->assertSame('smtp.panel.example', config('mail.mailers.managed_smtp.host'));

        $row->fill(['enabled' => false])->save();
        $this->svc()->apply();

        $this->assertNull(config('mail.mailers.managed_smtp'), 'no stale panel definition may remain resolvable');
        $this->assertStringNotContainsString('smtp.panel.example', (string) json_encode(config('mail')));
    }

    // ── Pre-first-apply storage failure fails CLOSED ────────────────────────

    public function test_fresh_process_storage_failure_fails_closed_never_environment_smtp(): void
    {
        // A service that has NEVER successfully resolved (fresh process)…
        $fresh = new EmailTransportSettingsService;

        $original = $this->breakStorage();
        try {
            $fresh->apply();

            // …must NOT continue with the environment transport: it cannot
            // know whether a panel override exists.
            $this->assertSame('managed_smtp', config('mail.default'));
            $this->assertSame('', config('mail.mailers.managed_smtp.host'));
            $this->assertTrue($fresh->isStorageFailClosed());
            $this->assertFalse($this->verification()->isMailConfigured());

            // Repeated identical failures cause no repeated purges.
            $purges = $fresh->purgeCount();
            $fresh->apply();
            $fresh->apply();
            $this->assertSame($purges, $fresh->purgeCount());
        } finally {
            $this->restoreStorage($original);
        }

        // Storage recovers: the real source applies WITHOUT a restart.
        $this->enabledRow();
        $fresh->apply();
        $this->assertSame('smtp.panel.example', config('mail.mailers.managed_smtp.host'));
        $this->assertFalse($fresh->isStorageFailClosed());
    }

    public function test_previously_applied_panel_configuration_survives_transient_storage_failure(): void
    {
        $this->enabledRow(['host' => 'smtp.a.example']);
        $this->svc()->apply();
        $this->assertSame('smtp.a.example', config('mail.mailers.managed_smtp.host'));

        $original = $this->breakStorage();
        try {
            $this->svc()->apply();

            // Temporary mid-process failure: the last successfully applied
            // configuration is retained — never reset, never fail-closed.
            $this->assertSame('smtp.a.example', config('mail.mailers.managed_smtp.host'));
            $this->assertSame('managed_smtp', config('mail.default'));
            $this->assertFalse($this->svc()->isStorageFailClosed());
        } finally {
            $this->restoreStorage($original);
        }
    }

    public function test_previously_applied_environment_configuration_survives_transient_storage_failure(): void
    {
        $envDefault = config('mail.default');
        $this->svc()->apply(); // no row → environment source applied

        $original = $this->breakStorage();
        try {
            $this->svc()->apply();

            $this->assertSame($envDefault, config('mail.default'), 'the environment configuration is retained');
            $this->assertFalse($this->svc()->isStorageFailClosed());
        } finally {
            $this->restoreStorage($original);
        }
    }

    // ── Database-enforced singleton ─────────────────────────────────────────

    public function test_repeated_instance_or_new_saves_update_exactly_one_singleton_row(): void
    {
        foreach (['a.example', 'b.example', 'c.example'] as $host) {
            $row = EmailTransportSetting::instanceOrNew();
            $row->fill(['host' => $host]);
            $row->save();
        }

        $this->assertSame(1, EmailTransportSetting::query()->count());
        $this->assertSame('c.example', EmailTransportSetting::instance()->host);
        $this->assertSame(EmailTransportSetting::SINGLETON_KEY, EmailTransportSetting::instance()->singleton_key);
    }

    public function test_the_database_itself_rejects_a_second_logical_row(): void
    {
        EmailTransportSetting::instanceOrNew()->save();

        // A direct second insert (bypassing every application-level check)
        // violates the fixed unique singleton key — the invariant is the
        // DATABASE's, not a convention.
        $this->expectException(QueryException::class);

        try {
            (new EmailTransportSetting(['enabled' => false]))->save();
        } finally {
            $this->assertSame(1, EmailTransportSetting::query()->count());
        }
    }
}
