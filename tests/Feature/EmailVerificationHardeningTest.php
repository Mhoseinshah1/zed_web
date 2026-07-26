<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailSettingsPage;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Jobs\SendEmailOtpJob;
use App\Mail\EmailOtpMail;
use App\Mail\TestEmailMail;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Services\Referrals\ReferralService;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Support\MailFailure;
use Aws\Ses\SesClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Tests\TestCase;

/**
 * Production-hardening coverage: email normalization + DB-level uniqueness,
 * transport-aware mail validation, sanitized delivery errors, honest queue
 * wording, optional/unconfigured flows, named rate limiters, race-safe job
 * claiming, and the registration transaction.
 */
class EmailVerificationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('email_verification_enabled', 'true');
        SiteSetting::set('email_verification_required_on_register', 'true');
        // Required mode demands a successful transport-test proof for the
        // CURRENT config (see EmailMailTestProofTest for the proof suite).
        app(EmailVerificationService::class)->recordSuccessfulMailTest();
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'کاربر تست',
            'username' => 'harduser'.random_int(10000, 99999),
            'email' => 'hard'.random_int(10000, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    private function unverifiedUser(array $attrs = []): User
    {
        // These suites exercise the ENFORCED path: the factory user carries
        // the per-user registration obligation marker.
        $user = User::factory()->create(array_merge(['email_verified_at' => null], $attrs));
        $user->forceFill(['email_verification_required_at_registration' => true])->save();

        return $user;
    }

    // ── Item 1: email normalization ──────────────────────────────────────────

    public function test_registration_normalizes_mixed_case_email_before_storing(): void
    {
        Mail::fake();

        $this->post('/register', $this->registrationPayload(['email' => '  MiXeD.Case@EXAMPLE.Com ']))
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'mixed.case@example.com')->first();
        $this->assertNotNull($user);
        // Exact stored value (SQLite `=` is case-sensitive): fully normalized.
        $this->assertSame('mixed.case@example.com', $user->getRawOriginal('email'));
    }

    public function test_registration_rejects_duplicate_differing_only_by_case_without_500(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->from('/register')
            ->post('/register', $this->registrationPayload(['email' => 'TAKEN@Example.COM']));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['taken@example.com'])->count());
    }

    public function test_model_level_safety_net_normalizes_every_writer(): void
    {
        // Direct model creation (imports, commands, future code paths).
        $user = User::factory()->create(['email' => '  Direct.Writer@EXAMPLE.COM ']);
        $this->assertSame('direct.writer@example.com', $user->fresh()->email);

        // And plain saves too.
        $user->email = 'Changed.AGAIN@Example.Com';
        $user->save();
        $this->assertSame('changed.again@example.com', $user->fresh()->email);
    }

    // ── Item 2: DB-level case-insensitive uniqueness ─────────────────────────

    private function uniquenessMigration(): object
    {
        return require database_path('migrations/2026_07_25_220000_enforce_case_insensitive_email_uniqueness.php');
    }

    public function test_db_index_refuses_second_address_differing_only_by_case(): void
    {
        $user = User::factory()->create(['email' => 'unique.addr@example.com']);

        $this->expectException(QueryException::class);
        // Bypass Eloquent (and its normalization hook) entirely: the DATABASE
        // itself must refuse the case-variant duplicate.
        DB::table('users')->insert([
            'name' => 'x', 'username' => 'dbdup1', 'account_id' => '999901',
            'email' => 'UNIQUE.ADDR@EXAMPLE.COM', 'password' => Hash::make('irrelevant1'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_db_index_refuses_second_address_differing_only_by_whitespace(): void
    {
        User::factory()->create(['email' => 'padded.addr@example.com']);

        $this->expectException(QueryException::class);
        // The index covers lower(TRIM(email)) — a DB-level writer (bulk
        // import, maintenance SQL) sneaking in trailing whitespace must
        // collide with the normalized row, not create a shadow account.
        DB::table('users')->insert([
            'name' => 'x', 'username' => 'dbdup2', 'account_id' => '999903',
            'email' => 'padded.addr@example.com  ', 'password' => Hash::make('irrelevant1'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_migration_aborts_on_existing_case_duplicates_without_modifying_data(): void
    {
        $migration = $this->uniquenessMigration();
        // Remove the index so pre-migration duplicates can exist.
        $migration->down();

        foreach ([['dupa1', 'Dup@Example.com'], ['dupa2', 'dup@example.com']] as [$username, $email]) {
            DB::table('users')->insert([
                'name' => 'x', 'username' => $username, 'account_id' => (string) random_int(100000, 999999),
                'email' => $email, 'password' => Hash::make('irrelevant1'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $migration->up();
            $this->fail('migration must abort on case-insensitive duplicates');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No data was modified', $e->getMessage());
        }

        // NOTHING was normalized or deleted.
        $this->assertSame(1, DB::table('users')->where('email', 'Dup@Example.com')->count());
        $this->assertSame(1, DB::table('users')->where('email', 'dup@example.com')->count());
    }

    public function test_migration_normalizes_existing_emails_preserving_verified_at_and_is_idempotent(): void
    {
        $migration = $this->uniquenessMigration();
        $migration->down();

        $verifiedAt = now()->subYear()->startOfSecond();
        DB::table('users')->insert([
            'name' => 'x', 'username' => 'legacymix', 'account_id' => '999902',
            'email' => ' Legacy.User@EXAMPLE.COM ', 'password' => Hash::make('irrelevant1'),
            'email_verified_at' => $verifiedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        // Idempotent: a second run is a no-op, not an error.
        $migration->up();

        $row = DB::table('users')->where('username', 'legacymix')->first();
        $this->assertSame('legacy.user@example.com', $row->email);
        $this->assertSame(
            $verifiedAt->toDateTimeString(),
            Carbon::parse($row->email_verified_at)->toDateTimeString(),
            'email_verified_at must be preserved',
        );
    }

    // ── Item 3: admin test-email action ──────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    public function test_test_email_refused_when_mail_unconfigured_never_false_success(): void
    {
        Mail::fake();
        // An undefined mailer name makes the config unusable in ANY env.
        config(['mail.default' => 'not-a-real-mailer']);

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        Mail::assertNothingSent();
    }

    public function test_test_email_uses_dedicated_mailable_and_honest_wording(): void
    {
        Mail::fake();

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com'])
            ->assertNotified('سرور ایمیل پیام تست را پذیرفت. رسیدن به صندوق ورودی را در مقصد بررسی کنید.');

        // The dedicated harmless mailable — NEVER a fake OTP.
        Mail::assertSent(TestEmailMail::class, 1);
    }

    public function test_disable_switch_works_during_a_mail_outage_even_with_required_left_on(): void
    {
        // The proof is gone (expired / transport now unusable) and required
        // was previously on — the admin must still be able to turn the
        // feature OFF without touching the required toggle.
        SiteSetting::set('email_mail_test_fingerprint', '');
        $this->assertFalse(app(EmailVerificationService::class)->hasVerifiedMailTest());

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => false,
                'email_verification_required_on_register' => true,
            ])
            ->call('save')
            ->assertNotified('تنظیمات ذخیره شد.');

        $this->assertFalse((bool) SiteSetting::get('email_verification_enabled'));
        $this->assertTrue((bool) SiteSetting::get('email_verification_required_on_register'));
        $this->assertFalse(app(EmailVerificationService::class)->isRequiredOnRegister());

        // The guard still bites the moment required mode would become ACTIVE:
        // re-enabling with the stale required toggle is refused unchanged.
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');

        $this->assertFalse((bool) SiteSetting::get('email_verification_enabled'), 'refused save writes nothing');
    }

    public function test_required_policy_pair_accepts_numeric_truthy_stored_values(): void
    {
        // SiteSetting::set casts to string — a boolean writer stores '1'.
        SiteSetting::set('email_verification_enabled', true);
        SiteSetting::set('email_verification_required_on_register', 1);

        $this->assertTrue(app(EmailVerificationService::class)->isRequiredOnRegister());
    }

    public function test_change_address_endpoint_is_gated_on_the_active_verification_flow(): void
    {
        Mail::fake();

        // An already-VERIFIED account: the mistyped-address fixer must never
        // double as a hidden self-service email changer.
        $verified = User::factory()->create([
            'email' => 'settled@example.com',
            'email_verified_at' => now()->subDay(),
            'password' => Hash::make('secret-pass-1'),
        ]);
        $this->actingAs($verified)->patch('/email/verification/change-address', [
            'email' => 'other@example.com', 'password' => 'secret-pass-1',
        ])->assertRedirect(route('dashboard.index'));
        $verified->refresh();
        $this->assertSame('settled@example.com', $verified->email, 'address untouched');
        $this->assertNotNull($verified->email_verified_at, 'verification untouched');

        // Feature DISABLED: the endpoint refuses even for unverified users —
        // no address change, no verification reset, no code issuance.
        SiteSetting::set('email_verification_enabled', 'false');
        $unverified = $this->unverifiedUser([
            'email' => 'pending@example.com',
            'password' => Hash::make('secret-pass-1'),
        ]);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($unverified)->patch('/email/verification/change-address', [
            'email' => 'sneaky@example.com', 'password' => 'secret-pass-1',
        ])->assertRedirect(route('dashboard.index'));
        $this->assertSame('pending@example.com', $unverified->fresh()->email, 'address untouched while disabled');
        $this->assertSame(0, EmailVerificationCode::count(), 'no code issued');
        Mail::assertNothingSent();
    }

    // ── Item 4: sanitized delivery errors ────────────────────────────────────

    public function test_dispatch_failure_stores_sanitized_error_and_frees_the_cooldown(): void
    {
        $user = $this->unverifiedUser();

        Queue::shouldReceive('connection')
            ->andThrow(new \RuntimeException('connect to smtp://mailer:SuperSecret99@mail.example.com:587 failed, password "SuperSecret99"'));
        Queue::shouldReceive('push')
            ->andThrow(new \RuntimeException('connect to smtp://mailer:SuperSecret99@mail.example.com:587 failed, password "SuperSecret99"'));

        $result = app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame('error', $result['status']);
        $record = EmailVerificationCode::first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, $record->send_status);
        $this->assertStringNotContainsString('SuperSecret99', (string) $record->send_error);
        // The never-queued attempt must not hold the cooldown or the cap.
        $this->assertTrue(app(EmailVerificationService::class)->canResend($user->fresh()));
        $this->assertFalse(app(EmailVerificationService::class)->reachedDailyCap($user->fresh()));
    }

    public function test_job_failed_hook_stores_category_not_raw_transport_text(): void
    {
        $user = $this->unverifiedUser();
        // An ABANDONED `sending` claim (early attempt claimed, later retries
        // burned out on contention): with every retry exhausted the claim is
        // demonstrably this job's own — failed() finalizes it as `failed`
        // (real transport attempt, never left actionable until expiry) with
        // a SANITIZED error and the claim fields cleared.
        $claimed = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENDING,
        ]);
        EmailVerificationCode::whereKey($claimed->id)->update([
            'delivery_claim_token' => bin2hex(random_bytes(32)), 'delivery_claimed_at' => now(),
        ]);
        (new SendEmailOtpJob($claimed->id, $user->id, (string) $user->email, '123456', 10))
            ->failed(new \RuntimeException('535 Authentication failed with password "TopSecret42"'));
        $claimed->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $claimed->send_status, 'abandoned claims are finalized, never left actionable');
        $this->assertNull($claimed->delivery_claim_token);
        $this->assertStringNotContainsString('TopSecret42', (string) $claimed->send_error);

        // An UNOWNED queued record (contention exhausted before any claim)
        // closes out as dispatch_failed with a SANITIZED error.
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '654321', 10))
            ->failed(new \RuntimeException('535 Authentication failed: Authorization: Basic dXNlcjpwYXNz with password "TopSecret42"'));

        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, $record->send_status);
        $this->assertStringNotContainsString('TopSecret42', (string) $record->send_error);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', (string) $record->send_error);
        $this->assertStringContainsString('auth_failed', (string) $record->send_error);
    }

    public function test_mail_failure_summaries_never_leak_credentials(): void
    {
        $samples = [
            new \RuntimeException('MAIL_PASSWORD=Hunter2Secret could not be used'),
            new \RuntimeException('smtp://deploy:Hunter2Secret@smtp.host:587 refused'),
            new \RuntimeException('unexpected: Authorization: Bearer abcDEF123456789'),
        ];

        foreach ($samples as $e) {
            $summary = MailFailure::summarize('delivery failed', $e);
            $this->assertStringNotContainsString('Hunter2Secret', $summary);
            $this->assertStringNotContainsString('abcDEF123456789', $summary);
            $this->assertLessThanOrEqual(MailFailure::MAX_LENGTH, mb_strlen($summary));
        }
    }

    // ── Item 5: honest queue wording ─────────────────────────────────────────

    public function test_resend_reports_queued_not_delivered(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();

        $result = app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame('کد تایید در صف ارسال قرار گرفت.', $result['message']);
    }

    // ── Item 6: optional / unconfigured flows ────────────────────────────────

    public function test_required_and_configured_redirects_to_verify_page_without_skip(): void
    {
        $user = $this->unverifiedUser();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
        $html = $this->actingAs($user)->get('/email/verify')->getContent();
        $this->assertStringNotContainsString('فعلاً بعداً', $html, 'required mode has no skip affordance');
    }

    public function test_optional_mode_shows_skip_and_dashboard_stays_accessible(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'false');
        Mail::fake();

        $this->post('/register', $this->registrationPayload(['email' => 'optional.flow@example.com']))
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'optional.flow@example.com')->first();
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count(), 'optional+configured still queues a code');

        $html = $this->actingAs($user)->get('/email/verify')->getContent();
        $this->assertStringContainsString('فعلاً بعداً', $html);
        $this->assertStringContainsString(route('dashboard.index'), $html);

        // Optional mode never blocks the dashboard, and the profile prompts.
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $profile = $this->actingAs($user)->get('/dashboard/profile')->getContent();
        $this->assertStringContainsString(route('verification.notice'), $profile);
    }

    public function test_enabled_but_unconfigured_never_strands_the_user(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'false');
        config(['mail.default' => 'not-a-real-mailer']);
        Queue::fake();

        $response = $this->post('/register', $this->registrationPayload(['email' => 'no.mailer@example.com']));

        // Straight to the dashboard with a warning — no OTP record, no job.
        $response->assertRedirect(route('dashboard.index'));
        $response->assertSessionHas('warning');
        $this->assertSame(0, EmailVerificationCode::count());
        Queue::assertNothingPushed();

        // Resend reports the configuration failure honestly.
        $user = User::where('email', 'no.mailer@example.com')->first();
        $this->actingAs($user)->from('/email/verify')->post('/email/verification/resend')
            ->assertSessionHas('error');
        $this->assertSame(0, EmailVerificationCode::count());
    }

    // ── Item 7: named rate limiters ──────────────────────────────────────────

    public function test_named_email_rate_limiters_are_registered(): void
    {
        foreach ([
            'email-verification-verify',
            'email-verification-resend',
            'email-verification-change',
            'email-test-send',
        ] as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), "limiter {$name} must be registered");
        }
    }

    public function test_verify_endpoint_throttled_per_user_and_ip(): void
    {
        $user = $this->unverifiedUser();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => '000000']);
        }

        $this->actingAs($user)->post('/email/verify', ['code' => '000000'])->assertStatus(429);
    }

    public function test_user_bucket_follows_the_account_across_source_ips(): void
    {
        $user = $this->unverifiedUser();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->from('/email/verify')->post('/email/verify', ['code' => '000000']);
        }

        // Replaying the session from a DIFFERENT IP must not mint a fresh
        // budget: the per-user bucket is already exhausted.
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.99.99.99'])
            ->post('/email/verify', ['code' => '000000'])
            ->assertStatus(429);
    }

    // ── Item 8: transport-aware mail validation ──────────────────────────────

    public function test_undefined_mailer_name_is_never_configured(): void
    {
        config(['mail.default' => 'smpt']);   // classic typo

        $this->assertFalse(app(EmailVerificationService::class)->isMailConfigured());
        $this->assertFalse(app(EmailVerificationService::class)->isRequiredOnRegister());
    }

    public function test_composite_cycle_is_detected_and_rejected(): void
    {
        config([
            'mail.default' => 'loopy',
            'mail.mailers.loopy' => ['transport' => 'failover', 'mailers' => ['loopy']],
        ]);

        $this->assertFalse(app(EmailVerificationService::class)->isMailConfigured());
    }

    public function test_empty_composite_is_rejected(): void
    {
        config([
            'mail.default' => 'hollow',
            'mail.mailers.hollow' => ['transport' => 'failover', 'mailers' => []],
        ]);

        $this->assertFalse(app(EmailVerificationService::class)->isMailConfigured());
    }

    public function test_smtp_with_and_without_auth_is_usable_but_hostless_is_not(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,   // authless relay is valid
            'mail.mailers.smtp.password' => null,
        ]);
        $this->assertTrue(app(EmailVerificationService::class)->isMailConfigured());

        config(['mail.mailers.smtp.host' => '']);
        $this->assertFalse(app(EmailVerificationService::class)->isMailConfigured());
    }

    public function test_ses_postmark_resend_transports_validate_their_credentials(): void
    {
        $svc = app(EmailVerificationService::class);

        // The repo's services.php defaults the SES region to us-east-1, so a
        // region alone must NOT count as configured — explicit keys required.
        // AND each API transport requires its runtime package: credentials
        // without the installed bridge still cannot construct a transport.
        config(['mail.default' => 'ses', 'services.ses.key' => '', 'services.ses.secret' => '']);
        $this->assertFalse($svc->isMailConfigured(), 'SES without credentials (default region alone)');
        config(['services.ses.key' => 'AKIATEST', 'services.ses.secret' => 'ses-test-secret']);
        $this->assertSame(
            class_exists(SesClient::class),
            $svc->isMailConfigured(),
            'SES with credentials is usable ONLY when aws/aws-sdk-php is installed',
        );

        // services.postmark.key is what config/services.php actually exposes.
        config(['mail.default' => 'postmark', 'services.postmark.key' => '']);
        $this->assertFalse($svc->isMailConfigured(), 'Postmark without a key');
        config(['services.postmark.key' => 'pm-test-key']);
        $this->assertSame(
            class_exists(PostmarkTransportFactory::class),
            $svc->isMailConfigured(),
            'Postmark with a key is usable ONLY when the Symfony bridge is installed',
        );

        config(['mail.default' => 'resend', 'services.resend.key' => '']);
        $this->assertFalse($svc->isMailConfigured(), 'Resend without a key');
        config(['services.resend.key' => 're-test-key']);
        $this->assertSame(
            class_exists(\Resend::class),
            $svc->isMailConfigured(),
            'Resend with a key is usable ONLY when resend/resend-php is installed',
        );
    }

    public function test_failover_with_log_member_is_rejected_in_production(): void
    {
        config(['mail.default' => 'failover']);   // repo default: smtp → log
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(app(EmailVerificationService::class)->isMailConfigured());
    }

    // ── Item 9: race-safe job claiming ───────────────────────────────────────

    /** Create a queued OTP record + its job without running the queue. */
    private function queuedJob(User $user, string $code = '123456'): array
    {
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        return [$record, new SendEmailOtpJob($record->id, $user->id, (string) $user->email, $code, 10)];
    }

    public function test_resend_makes_the_older_queued_job_obsolete(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        [$old, $oldJob] = $this->queuedJob($user);
        // A resend supersedes it (newer active record for the same user).
        [$new] = $this->queuedJob($user);

        $oldJob->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $old->fresh()->send_status);
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $new->fresh()->send_status, 'only the NEWEST code may be delivered');
    }

    public function test_address_change_makes_the_old_job_obsolete(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser(['email' => 'before@example.com']);
        [$record, $job] = $this->queuedJob($user);

        $user->forceFill(['email' => 'after@example.com'])->save();

        $job->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    public function test_already_verified_user_gets_no_late_otp_email(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        [$record, $job] = $this->queuedJob($user);

        $user->forceFill(['email_verified_at' => now()])->save();

        $job->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    public function test_second_worker_cannot_double_send_a_claimed_job(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        [$record, $job] = $this->queuedJob($user);

        // Worker A already claimed the record (queued → sending).
        EmailVerificationCode::whereKey($record->id)
            ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SENDING]);

        // Worker B (first attempt) must NOT re-claim or send.
        $job->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $record->fresh()->send_status);
    }

    public function test_successful_claim_walks_queued_sending_sent_and_delivers_once(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        [$record, $job] = $this->queuedJob($user);

        $job->handle();

        Mail::assertSentCount(1);
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);

        // Re-delivery of the same job is a no-op: terminal states are final.
        $job->handle();
        Mail::assertSentCount(1);
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
    }

    // ── Locked-user authority during issuance ────────────────────────────────

    public function test_request_code_uses_the_locked_row_not_the_stale_caller_model(): void
    {
        Queue::fake();
        $user = $this->unverifiedUser(['email' => 'stale.old@example.com']);
        $stale = User::find($user->id);

        // Another request commits an address change BEFORE the lock is taken.
        $user->forceFill(['email' => 'fresh.new@example.com'])->save();

        $result = app(EmailVerificationService::class)->requestCode($stale);

        $this->assertSame('queued', $result['status']);
        // No record and no job may target the corrected-away address.
        $this->assertSame(0, EmailVerificationCode::where('email', 'stale.old@example.com')->count());
        $record = EmailVerificationCode::where('email', 'fresh.new@example.com')->first();
        $this->assertNotNull($record, 'the CURRENT email receives the record');
        Queue::assertPushed(SendEmailOtpJob::class, function (SendEmailOtpJob $job) {
            $email = (new \ReflectionProperty($job, 'email'))->getValue($job);

            return $email === 'fresh.new@example.com';
        });
    }

    public function test_request_code_for_a_deleted_user_returns_an_honest_error(): void
    {
        Queue::fake();
        $user = $this->unverifiedUser();
        $stale = User::find($user->id);
        $user->delete();

        $result = app(EmailVerificationService::class)->requestCode($stale);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, EmailVerificationCode::count());
        Queue::assertNothingPushed();
    }

    public function test_never_dispatched_and_skipped_records_block_neither_resend_nor_the_daily_cap(): void
    {
        SiteSetting::set('email_otp_daily_cap', 2);
        $user = $this->unverifiedUser();
        $svc = app(EmailVerificationService::class);

        foreach ([EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, EmailVerificationCode::SEND_STATUS_SKIPPED] as $i => $status) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('00000'.$i),
                'expires_at' => now()->addMinutes(10), 'attempts' => 0,
                'send_status' => $status,
            ]);
        }

        // Terminal obsolete records never attempted a real delivery: an
        // immediate retry is allowed and the daily allowance is untouched.
        $this->assertTrue($svc->canResend($user));
        $this->assertFalse($svc->reachedDailyCap($user));
        Mail::fake();
        $this->assertSame('queued', $svc->requestCode($user)['status']);
    }

    // ── Bounded per-user locking ─────────────────────────────────────────────

    public function test_lock_contention_returns_a_bounded_controlled_busy_response(): void
    {
        Queue::fake();
        $user = $this->unverifiedUser();
        $lock = Cache::lock(
            EmailVerificationService::userLockKey($user->id), 60
        );
        $this->assertTrue($lock->get(), 'test precondition: hold the per-user lock');

        try {
            $started = microtime(true);
            $result = app(EmailVerificationService::class)->requestCode($user);
            $elapsed = microtime(true) - $started;

            // Controlled Persian retry message, no partial writes, BOUNDED wait.
            $this->assertSame('busy', $result['status']);
            $this->assertSame(EmailVerificationService::BUSY_MESSAGE, $result['message']);
            $this->assertSame(0, EmailVerificationCode::count());
            Queue::assertNothingPushed();
            $this->assertLessThan(
                EmailVerificationService::LOCK_WAIT_SECONDS + 5,
                $elapsed,
                'the lock wait must be strictly bounded',
            );

            // verify() and changeAddressTo() fail closed the same way.
            $verify = app(EmailVerificationService::class)->verify($user, '123456');
            $this->assertSame('busy', $verify['status']);

            $before = $user->email;
            $this->assertFalse(app(EmailVerificationService::class)->changeAddressTo($user, 'other@example.com'));
            $this->assertSame($before, $user->fresh()->email, 'no partial change on lock failure');
        } finally {
            $lock->release();
        }
    }

    // ── Concurrent registration email collisions ─────────────────────────────

    public function test_db_level_email_collision_during_registration_becomes_a_validation_error(): void
    {
        Queue::fake();
        $telegram = $this->mock(TelegramAdminNotifier::class);
        $telegram->shouldNotReceive('event');

        // Simulate the TOCTOU race deterministically: a competing registration
        // commits the same normalized address AFTER this request's validation
        // passed but BEFORE its insert — injected via a creating hook so the
        // DB unique index (the final authority) fires inside the transaction.
        $fired = false;
        User::creating(function () use (&$fired) {
            if (! $fired) {
                $fired = true;
                DB::table('users')->insert([
                    'name' => 'racer', 'username' => 'race_winner', 'account_id' => '999903',
                    'email' => 'raced@example.com', 'password' => Hash::make('irrelevant1'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->from('/register')
            ->post('/register', $this->registrationPayload(['email' => 'raced@example.com']));

        // A clean validation error — never an HTTP 500, never constraint text.
        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        // The losing transaction rolled back completely: no partial user, no
        // OTP record, no queued job, no Telegram notification.
        $this->assertSame(0, EmailVerificationCode::count());
        Queue::assertNothingPushed();
    }

    public function test_unrelated_unique_violations_are_not_masked_as_email_errors(): void
    {
        // A username collision (not email) must stay a real error, not become
        // an email validation message.
        User::factory()->create(['username' => 'collide_user']);

        $fired = false;
        User::creating(function (User $u) use (&$fired) {
            if (! $fired) {
                $fired = true;
                // Force a USERNAME collision at insert time.
                $u->username = 'collide_user';
            }
        });

        $response = $this->post('/register', $this->registrationPayload(['email' => 'unrelated@example.com']));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse(session()->has('errors'));
    }

    // ── Admin test-email independent buckets ─────────────────────────────────

    public function test_admin_user_bucket_follows_the_admin_across_ips(): void
    {
        Mail::fake();
        $admin = $this->admin();

        // Exhaust the per-admin bucket directly (as if from other IPs).
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('ets:u:'.$admin->getAuthIdentifier(), 600);
        }

        Livewire::actingAs($admin)
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        Mail::assertNothingSent();
    }

    public function test_ip_bucket_caps_admins_cycling_accounts_from_one_machine(): void
    {
        Mail::fake();

        // Exhaust the per-IP bucket for the test client IP.
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('ets:ip:127.0.0.1', 600);
        }

        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        Mail::assertNothingSent();
    }

    public function test_fresh_admin_and_ip_remain_unaffected_by_other_buckets(): void
    {
        Mail::fake();
        $blocked = $this->admin();
        foreach (range(1, 3) as $i) {
            RateLimiter::hit('ets:u:'.$blocked->getAuthIdentifier(), 600);
        }

        // A DIFFERENT admin (fresh user bucket) on a clean IP bucket sends fine.
        RateLimiter::clear('ets:ip:127.0.0.1');
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->callAction('testEmail', ['test_email' => 'probe@example.com']);

        Mail::assertSent(TestEmailMail::class, 1);
    }

    // ── Per-user registration obligation (immutable policy marker) ───────────

    private function registeredUser(string $email): User
    {
        // Registration logs the new user in, and earlier actingAs() calls are
        // sticky across test requests — reset the guards so each registration
        // arrives as a genuine guest.
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->post('/register', $this->registrationPayload(['email' => $email]))
            ->assertStatus(302);
        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);

        auth()->logout();
        $this->app['auth']->forgetGuards();

        return $user;
    }

    public function test_obligation_flag_captures_the_effective_policy_at_registration(): void
    {
        Mail::fake();

        // Effective required mode active → obligated.
        $obligated = $this->registeredUser('obligated@example.com');
        $this->assertTrue((bool) $obligated->email_verification_required_at_registration);
        $this->actingAs($obligated)->get('/dashboard')->assertRedirect(route('verification.notice'));

        // Optional mode → not obligated.
        SiteSetting::set('email_verification_required_on_register', 'false');
        $optional = $this->registeredUser('optional.marker@example.com');
        $this->assertFalse((bool) $optional->email_verification_required_at_registration);

        // Feature disabled → not obligated.
        SiteSetting::set('email_verification_enabled', 'false');
        $disabled = $this->registeredUser('disabled.marker@example.com');
        $this->assertFalse((bool) $disabled->email_verification_required_at_registration);
    }

    public function test_fail_safe_registrations_are_never_retroactively_locked_out(): void
    {
        Mail::fake();
        $svc = app(EmailVerificationService::class);

        // Raw toggle stays TRUE the whole time, but the effective policy is
        // inactive: expired proof, drifted fingerprint, unusable mailer.
        $failSafeUsers = [];

        $this->travel(EmailVerificationService::MAIL_TEST_PROOF_MAX_DAYS + 1)->days();   // proof expired
        $this->assertFalse($svc->isRequiredOnRegister());
        $failSafeUsers[] = $this->registeredUser('expired.proof@example.com');

        $svc->recordSuccessfulMailTest();
        config(['mail.from.address' => 'drifted-from@example.com']);                      // fingerprint drift
        $this->assertFalse($svc->isRequiredOnRegister());
        $failSafeUsers[] = $this->registeredUser('drifted.proof@example.com');

        config(['mail.from.address' => 'noreply@example.com', 'mail.default' => 'not-a-real-mailer']); // unusable
        $this->assertFalse($svc->isRequiredOnRegister());
        $failSafeUsers[] = $this->registeredUser('unusable.mailer@example.com');

        // The mail service RECOVERS: fresh proof, effective required again.
        config(['mail.default' => 'array']);
        $svc->recordSuccessfulMailTest();
        $this->assertTrue($svc->isRequiredOnRegister());

        // Every fail-safe registration keeps its false marker and full access
        // FOREVER — no retroactive lockout after proof/credential restoration.
        foreach ($failSafeUsers as $user) {
            $this->assertFalse((bool) $user->fresh()->email_verification_required_at_registration);
            $this->actingAs($user)->get('/dashboard')->assertOk();
        }

        // While a user registered under the enforced policy stays obligated
        // after the outage window closed.
        $obligated = $this->registeredUser('post.recovery@example.com');
        $this->assertTrue((bool) $obligated->email_verification_required_at_registration);
        $this->actingAs($obligated)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_toggling_required_mode_never_rewrites_existing_markers(): void
    {
        Mail::fake();
        $obligated = $this->registeredUser('sticky.true@example.com');
        SiteSetting::set('email_verification_required_on_register', 'false');
        $free = $this->registeredUser('sticky.false@example.com');

        // Flip the policy off and on again — existing flags are untouched.
        SiteSetting::set('email_verification_required_on_register', 'true');
        SiteSetting::set('email_verification_required_on_register', 'false');
        SiteSetting::set('email_verification_required_on_register', 'true');

        $this->assertTrue((bool) $obligated->fresh()->email_verification_required_at_registration);
        $this->assertFalse((bool) $free->fresh()->email_verification_required_at_registration);
        $this->actingAs($free)->get('/dashboard')->assertOk();
        $this->actingAs($obligated)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_obligated_users_are_not_locked_during_a_transport_outage(): void
    {
        Mail::fake();
        $obligated = $this->registeredUser('outage.window@example.com');
        $this->assertTrue((bool) $obligated->email_verification_required_at_registration);

        // The mailer becomes unusable: enforcement fails safe TEMPORARILY…
        config(['mail.default' => 'not-a-real-mailer']);
        $this->actingAs($obligated)->get('/dashboard')->assertOk();

        // …and resumes for the obligated user once the transport recovers.
        config(['mail.default' => 'array']);
        app(EmailVerificationService::class)->recordSuccessfulMailTest();
        $this->actingAs($obligated)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_backfilled_users_without_the_marker_remain_accessible(): void
    {
        // Factory users mirror pre-migration accounts: marker false (column
        // default) — the middleware never blocks them even under required mode.
        $legacy = User::factory()->create(['email_verified_at' => null]);
        $this->assertFalse((bool) $legacy->email_verification_required_at_registration);

        $this->actingAs($legacy)->get('/dashboard')->assertOk();
    }

    // ── Atomic Filament admin update ─────────────────────────────────────────

    public function test_admin_update_is_all_or_nothing_on_late_failure(): void
    {
        Queue::fake();
        User::factory()->create(['username' => 'blocking_name']);
        $user = User::factory()->create([
            'email' => 'atomic.before@example.com', 'email_verified_at' => now(),
            'name' => 'Original Name',
        ]);
        $activeCode = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        // The email change is VALID; the username collides — the single UPDATE
        // fails after the email mutation logic already ran, and EVERYTHING
        // must roll back together.
        try {
            app(EmailVerificationService::class)->applyAdminUpdate($user, [
                'email' => 'atomic.after@example.com',
                'email_verification_action' => 'require_verification',
                'username' => 'blocking_name',
                'name' => 'Changed Name',
                'is_admin' => true,
            ]);
            $this->fail('the username collision must surface');
        } catch (QueryException $e) {
            $this->assertStringNotContainsString('این ایمیل', $e->getMessage(), 'unrelated violations are never mislabeled');
        }

        $user->refresh();
        $this->assertSame('atomic.before@example.com', $user->email, 'email rolled back');
        $this->assertNotNull($user->email_verified_at, 'verification timestamp rolled back');
        $this->assertSame('Original Name', $user->name, 'other fields rolled back');
        $this->assertFalse((bool) $user->is_admin, 'is_admin rolled back');
        $this->assertNull($activeCode->fresh()->used_at, 'OTP records remain active');
        Queue::assertNothingPushed();
    }

    public function test_admin_update_commits_email_is_admin_and_fields_together(): void
    {
        $user = User::factory()->create(['email' => 'combo.before@example.com', 'email_verified_at' => now()]);
        $staleCode = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        $updated = app(EmailVerificationService::class)->applyAdminUpdate($user, [
            'email' => 'Combo.After@Example.com',
            'email_verification_action' => 'require_verification',
            'name' => 'Renamed',
            'is_admin' => true,
        ]);

        // ONE commit carries everything — including is_admin alongside the
        // email change (previously refresh() could silently drop it).
        $this->assertSame('combo.after@example.com', $updated->email);
        $this->assertNull($updated->email_verified_at);
        $this->assertSame('Renamed', $updated->name);
        $this->assertTrue((bool) $updated->is_admin);
        $this->assertNotNull($staleCode->fresh()->used_at, 'old codes died with the same commit');
    }

    public function test_admin_update_lock_contention_is_a_controlled_field_error(): void
    {
        $user = User::factory()->create(['email' => 'locked.admin@example.com', 'email_verified_at' => now()]);
        $lock = Cache::lock(EmailVerificationService::userLockKey($user->id), 60);
        $this->assertTrue($lock->get());

        try {
            app(EmailVerificationService::class)->applyAdminUpdate($user, [
                'email' => 'moved.admin@example.com', 'name' => 'x',
            ]);
            $this->fail('contention must be refused');
        } catch (ValidationException $e) {
            $this->assertSame([EmailVerificationService::BUSY_MESSAGE], $e->errors()['data.email']);
        } finally {
            $lock->release();
        }

        $this->assertSame('locked.admin@example.com', $user->fresh()->email, 'no partial edits');
    }

    // ── Expired codes never hold the resend cooldown ─────────────────────────

    public function test_expired_codes_do_not_hold_a_longer_cooldown_hostage(): void
    {
        Mail::fake();
        SiteSetting::set('email_otp_ttl_minutes', 1);
        SiteSetting::set('email_otp_resend_cooldown_seconds', 3600);
        $user = $this->unverifiedUser();
        $svc = app(EmailVerificationService::class);

        // The requested 1-minute TTL is clamped to the floor (codes must
        // outlive the delivery-claim margin) — still far below the cooldown.
        $this->assertSame(EmailVerificationService::MIN_TTL_MINUTES, $svc->ttlMinutes());
        $this->assertSame('queued', $svc->requestCode($user)['status']);

        // The code expires long before the (misconfigured) hour-long cooldown:
        // "request a new code" must actually be possible.
        $this->travel(EmailVerificationService::MIN_TTL_MINUTES + 1)->minutes();
        $this->assertTrue($svc->canResend($user));
        $this->assertSame('queued', $svc->requestCode($user)['status']);
    }

    public function test_clamped_minimum_ttl_still_yields_a_deliverable_code(): void
    {
        Mail::fake();
        SiteSetting::set('email_otp_ttl_minutes', 1);
        $user = $this->unverifiedUser();

        // The floor keeps every fresh code above the job's claim margin: it
        // must actually SEND (sync queue), never skip as margin-starved.
        $result = app(EmailVerificationService::class)->requestCode($user);
        $this->assertTrue((bool) ($result['email_sent'] ?? false));
        Mail::assertSentCount(1);
        $this->assertSame(
            EmailVerificationCode::SEND_STATUS_SENT,
            EmailVerificationCode::latest('id')->first()->send_status,
        );
    }

    public function test_a_live_transport_outage_suspends_enforcement_until_a_delivery_succeeds(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();
        $this->assertTrue($svc->isEnforceableNow(), 'healthy baseline (setUp proof)');
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));

        // The endpoint dies WITHOUT any config change: the latest three
        // finalized transport outcomes are all failures.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }

        $this->assertFalse($svc->transportLooksLive());
        $this->assertFalse($svc->isEnforceableNow(), 'live outage pauses enforcement');
        $this->assertFalse($svc->isRequiredOnRegister(), 'registrations are not stamped during an outage');
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->get('/dashboard')->assertOk('obligated users are not blocked behind a dead mailer');

        // One successful delivery clears the signal — enforcement resumes.
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
            'delivery_finalized_at' => now(),
            'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
        ]);
        $this->assertTrue($svc->transportLooksLive());
        $this->assertTrue($svc->isEnforceableNow());
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_out_of_order_recovery_clears_the_outage_signal(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // An OLDER queued code (lower id) …
        $older = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENDING,
        ]);
        // … while three NEWER codes fail first.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }
        $this->assertFalse($svc->transportLooksLive());

        // The OLDER code finalizes successfully AFTER those failures: health
        // is judged by FINALIZATION time, so recovery clears the outage even
        // though every failure has a higher issuance id.
        $this->travel(1)->minutes();
        EmailVerificationCode::whereKey($older->id)
            ->update(['send_status' => EmailVerificationCode::SEND_STATUS_SENT, 'delivery_finalized_at' => now(), 'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint()]);
        $this->travelBack();

        $this->assertTrue($svc->transportLooksLive(), 'out-of-order success is still a recovery');
    }

    public function test_general_mutations_of_old_outcomes_never_fake_a_recovery(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // An old success finalized LONG before the outage …
        $old = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
            'delivery_finalized_at' => now()->subHours(2),
            'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
        ]);
        // … then the endpoint dies: three fresh failures.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }
        $this->assertFalse($svc->transportLooksLive());

        // Invalidating (or verifying) the OLD sent row moves updated_at but
        // NOT its delivery outcome — the outage must persist.
        $svc->invalidateCodes($user->fresh());
        $this->assertNotNull($old->fresh()->used_at);
        $this->assertFalse($svc->transportLooksLive(), 'a general mutation is not a fresh delivery success');
    }

    public function test_superseded_queued_codes_never_burn_the_daily_cap(): void
    {
        Queue::fake();
        SiteSetting::set('email_otp_resend_cooldown_seconds', 0);
        SiteSetting::set('email_otp_daily_cap', 3);
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // A backlog: jobs never run, every resend supersedes a still-queued
        // record. Those rows produced ZERO deliveries — the cap must ignore
        // them, or the user exhausts the allowance while the backlog drains.
        foreach (range(1, 3) as $i) {
            $this->assertSame('queued', $svc->requestCode($user->fresh())['status'], "resend #{$i} within the cap");
        }

        $superseded = EmailVerificationCode::where('user_id', $user->id)
            ->where('send_status', EmailVerificationCode::SEND_STATUS_SKIPPED)
            ->count();
        $this->assertSame(2, $superseded, 'every superseded queued row was finalized as skipped');
        $this->assertFalse($svc->reachedDailyCap($user->fresh()), 'only the single live queued row counts');
    }

    public function test_policy_capture_recreates_missing_rows_so_the_lock_has_a_target(): void
    {
        // Fresh-install shape: the policy rows do not exist at all.
        SiteSetting::where('key', 'email_verification_enabled')->delete();
        SiteSetting::where('key', 'email_verification_required_on_register')->delete();

        $captured = DB::transaction(fn () => app(EmailVerificationService::class)->captureRequiredPolicyForRegistration());

        $this->assertFalse($captured, 'missing rows read as an OFF policy');
        // Both rows now physically exist (default false) — future captures
        // have concrete rows to share-lock against a concurrent policy save.
        $this->assertSame('false', SiteSetting::where('key', 'email_verification_enabled')->value('value'));
        $this->assertSame('false', SiteSetting::where('key', 'email_verification_required_on_register')->value('value'));
    }

    public function test_a_successful_transport_test_after_the_failures_clears_the_outage(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }
        $this->assertFalse($svc->transportLooksLive(), 'the setUp proof PRE-dates the failures — no clearance');

        // The operator repairs/replaces the configuration and runs a
        // successful per-leaf transport test AFTER the newest failure:
        // positive, fingerprint-bound live evidence — enforcement resumes
        // without waiting for a real OTP or the window to expire.
        $this->travel(1)->minutes();
        $svc->recordSuccessfulMailTest();
        $this->assertTrue($svc->transportLooksLive(), 'a post-failure certified test is a recovery');
        $this->assertTrue($svc->isEnforceableNow());
        $this->travelBack();
    }

    public function test_a_queue_outage_pauses_enforcement_like_a_mail_outage(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // The queue connection is down: every resend dies BEFORE any
        // transport attempt. Users cannot receive codes either way — the
        // delivery pipeline is broken, so enforcement must fail open.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }

        $this->assertFalse($svc->transportLooksLive(), 'dispatch failures are pipeline-outage evidence');
        $this->assertFalse($svc->isEnforceableNow());
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        // A successful admin MAIL test proves nothing about the queue — it
        // sends synchronously. The queue-outage category stays paused until
        // a real queued delivery succeeds (or the window expires).
        $this->travel(1)->minutes();
        $svc->recordSuccessfulMailTest();
        $this->assertFalse($svc->transportLooksLive(), 'a synchronous mail test never clears a queue outage');

        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
            'delivery_finalized_at' => now(),
            'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
        ]);
        $this->assertTrue($svc->transportLooksLive(), 'a real queued delivery clears it');
        $this->travelBack();
    }

    public function test_unscoped_legacy_outcomes_never_count_against_the_current_config(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // Rolling deployment: pre-fingerprint workers finalize failures with
        // a NULL fingerprint — nothing proves they belong to the CURRENT
        // configuration, so they are not outage evidence for it.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
            ]);
        }

        $this->assertTrue($svc->transportLooksLive(), 'null-fingerprint rows are ignored');
    }

    public function test_a_dead_lock_backend_suspends_enforcement(): void
    {
        $svc = app(EmailVerificationService::class);
        $this->assertTrue($svc->lockBackendLooksAvailable(), 'healthy baseline');
        $this->assertTrue($svc->isEnforceableNow());

        // The cache-lock backend dies while app + DB stay up: requestCode()
        // and verify() would both fail closed before any outcome row exists —
        // enforcement must fail open instead of stranding obligated users.
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('redis connection refused'));
        $this->assertFalse($svc->lockBackendLooksAvailable());
        $this->assertFalse($svc->isEnforceableNow(), 'a dead lock backend pauses enforcement');
        $this->assertFalse($svc->isRequiredOnRegister(), 'and registration stamping');
    }

    public function test_stale_config_failures_never_suspend_the_current_configuration(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // A long-lived worker still running a REPLACED configuration
        // finalizes its failures late — stamped with the OLD fingerprint.
        foreach (range(1, 3) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => str_repeat('0', 64),
            ]);
        }

        // Outcomes of a config that is no longer in use say nothing about
        // the CURRENT one — enforcement stays armed.
        $this->assertTrue($svc->transportLooksLive(), 'stale-config failures are filtered out');
        $this->assertTrue($svc->isEnforceableNow());
    }

    public function test_intended_destination_is_stored_relative_never_with_the_request_host(): void
    {
        $user = $this->unverifiedUser();

        // A proxy accepting arbitrary Host values must not let the header
        // become an absolute post-verification redirect target.
        $this->actingAs($user)
            ->get('http://evil.attacker.example/dashboard')
            ->assertRedirect(route('verification.notice'));

        $this->assertSame('/dashboard', session('url.intended'), 'relative target only — no attacker-influenced host');
    }

    public function test_recipient_rejections_never_fabricate_a_global_outage(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();

        // Deliberately rejectable addresses: the transport is FINE — only
        // these recipients bounced. Any number of them must not flip
        // required verification off for everyone.
        foreach (range(1, 5) as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
                'send_error' => 'delivery failed: recipient_rejected (TransportException)',
                'delivery_finalized_at' => now(),
                'delivery_config_fingerprint' => app(EmailVerificationService::class)->mailConfigFingerprint(),
            ]);
        }

        $this->assertTrue($svc->transportLooksLive(), 'recipient bounces are not outage evidence');
        $this->assertTrue($svc->isEnforceableNow());
        $this->assertTrue($svc->isRequiredOnRegister());
    }

    // ── Honest lifetimes & synchronous delivery failures ─────────────────────

    public function test_notice_page_advertises_the_remaining_lifetime_of_the_active_code(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        app(EmailVerificationService::class)->requestCode($user);

        // Nine of the ten configured minutes have passed.
        $this->travel(9)->minutes();

        $remaining = app(EmailVerificationService::class)->activeCodeRemainingMinutes($user->fresh());
        $this->assertSame(1, $remaining, 'the code has one minute left, not ten');

        $html = $this->actingAs($user)->get('/email/verify')->getContent();
        $this->assertStringContainsString('1 دقیقه', $html);
        $this->assertStringNotContainsString('10 دقیقه', $html, 'never advertise the full TTL for a dying code');
    }

    public function test_sync_queue_transport_failure_stays_a_counted_delivery_failure(): void
    {
        SiteSetting::set('email_otp_daily_cap', 1);
        $user = $this->unverifiedUser();

        // The sync driver executes the handler inline: the transport throws,
        // the job's failed() hook records the honest outcome, and the
        // exception then reaches requestCode's dispatch catch.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection timed out'));

        $result = app(EmailVerificationService::class)->requestCode($user);

        $this->assertSame('error', $result['status']);
        $record = EmailVerificationCode::first();
        // A REAL transport attempt: `failed`, not `dispatch_failed` — and it
        // burns the daily allowance so repeated failures can't loop forever.
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $record->send_status);
        $this->assertTrue(app(EmailVerificationService::class)->reachedDailyCap($user->fresh()));
    }

    // ── Job payload ownership ────────────────────────────────────────────────

    public function test_job_with_mismatched_record_ownership_sends_nothing(): void
    {
        Mail::fake();
        $owner = $this->unverifiedUser();
        $other = $this->unverifiedUser();
        $record = EmailVerificationCode::create([
            'user_id' => $owner->id, 'email' => $owner->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        // Payload claims a DIFFERENT user than the record's owner.
        (new SendEmailOtpJob($record->id, $other->id, (string) $owner->email, '123456', 10))->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    // ── Address-change collisions: the DB index is the final authority ───────

    public function test_lost_address_change_race_becomes_a_validation_error_with_nothing_changed(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'occupied@example.com']);
        $loser = $this->unverifiedUser(['email' => 'loser.before@example.com']);
        $keptCode = EmailVerificationCode::create([
            'user_id' => $loser->id, 'email' => $loser->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        // Straight to the service (as a TOCTOU race would after the
        // controller's pre-validation already passed).
        try {
            app(EmailVerificationService::class)->changeAddressTo($loser, 'OCCUPIED@Example.com');
            $this->fail('the DB unique index must win the race');
        } catch (ValidationException $e) {
            $this->assertSame(['این ایمیل قبلاً ثبت شده است.'], $e->errors()['email']);
        }

        // The losing transaction rolled back COMPLETELY.
        $loser->refresh();
        $this->assertSame('loser.before@example.com', $loser->email);
        $this->assertNull($loser->email_verified_at);
        $this->assertNull($keptCode->fresh()->used_at, 'existing OTP records stay untouched');
        Mail::assertNothingSent();
    }

    public function test_filament_edit_translates_email_collision_and_keeps_unrelated_errors(): void
    {
        User::factory()->create(['email' => 'held@example.com']);
        $user = User::factory()->create(['email' => 'editable@example.com', 'email_verified_at' => now()]);

        $page = new EditUser;
        $method = new \ReflectionMethod($page, 'handleRecordUpdate');

        // Mixed-case collision → a normal field validation error, never a 500.
        try {
            $method->invoke($page, $user, ['email' => 'HELD@Example.COM', 'email_verification_action' => 'mark_verified']);
            $this->fail('colliding admin email change must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.email', $e->errors());
        }
        // A refused change never silently marks anything verified/unverified.
        $user->refresh();
        $this->assertSame('editable@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        // Unrelated unique violations (username) still surface as real errors.
        User::factory()->create(['username' => 'occupied_name']);
        $this->expectException(QueryException::class);
        $method->invoke($page, $user, ['username' => 'occupied_name']);
    }

    public function test_filament_create_translates_email_collision(): void
    {
        User::factory()->create(['email' => 'created.first@example.com']);

        $page = new CreateUser;
        $method = new \ReflectionMethod($page, 'handleRecordCreation');

        try {
            $method->invoke($page, [
                'name' => 'x', 'username' => 'fresh_admin_made',
                'email' => 'Created.FIRST@example.com', 'password' => Hash::make('irrelevant1'),
            ]);
            $this->fail('colliding admin-created email must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.email', $e->errors());
        }
        $this->assertSame(0, User::where('username', 'fresh_admin_made')->count());
    }

    // ── Job lock/retry safety ────────────────────────────────────────────────

    public function test_email_advertises_the_remaining_lifetime_not_the_full_ttl(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        // Issued earlier: only ~3 minutes of validity remain by delivery time.
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(3)->addSeconds(30), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();

        Mail::assertSent(EmailOtpMail::class, fn ($mail) => $mail->ttlMinutes === 3);
    }

    public function test_mid_send_invalidation_finalizes_as_skipped_not_sent(): void
    {
        $user = $this->unverifiedUser();
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        // The transport call happens AFTER the claim; simulate a competing
        // invalidation landing exactly during the SMTP conversation.
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->once();
        Mail::shouldReceive('to')->andReturnUsing(function () use ($record, $pending) {
            EmailVerificationCode::whereKey($record->id)->update(['used_at' => now()]);

            return $pending;
        });

        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();

        // NEVER reported as a delivered, still-actionable code.
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    public function test_job_contention_releases_without_sending_or_failing_the_record(): void
    {
        Mail::fake();
        $user = $this->unverifiedUser();
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        $lock = Cache::lock(EmailVerificationService::userLockKey($user->id), 60);
        $this->assertTrue($lock->get());
        try {
            (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
            $this->fail('without a queue context, contention must SURFACE — a silent return would fake success');
        } catch (\RuntimeException $e) {
            // Static, secret-free message; a real worker (with a queue
            // context) releases for retry instead of throwing.
            $this->assertStringContainsString('lock_contention', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        } finally {
            $lock->release();
        }

        Mail::assertNothingSent();
        // The claim never ran: the record is untouched for the retry/failed()
        // accounting of whichever driver executed the job.
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $record->fresh()->send_status);
    }

    public function test_failed_hook_never_downgrades_a_terminal_sent_state(): void
    {
        $user = $this->unverifiedUser();
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))
            ->failed(new \RuntimeException('late failure after acceptance'));

        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
    }

    public function test_delivery_failures_count_toward_the_daily_cap(): void
    {
        SiteSetting::set('email_otp_daily_cap', 2);
        $user = $this->unverifiedUser();

        // Two DELIVERY failures = up to six real transport attempts already.
        foreach ([1, 2] as $i) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('00000'.$i),
                'expires_at' => now()->addMinutes(10), 'attempts' => 0,
                'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
            ]);
        }

        $this->assertTrue(
            app(EmailVerificationService::class)->reachedDailyCap($user),
            'real transport attempts burn the allowance — only never-dispatched/skipped records are free',
        );
    }

    // ── Admin verification-state semantics (explicit actions, no raw dates) ──

    public function test_admin_verification_actions_are_explicit_and_invalidate_codes(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::factory()->create(['email' => 'action.user@example.com', 'email_verified_at' => null]);
        $pending = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        // `keep` + unrelated fields: verification state untouched.
        $svc->applyAdminUpdate($user, ['name' => 'Renamed', 'email_verification_action' => 'keep']);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($pending->fresh()->used_at, 'keep leaves pending codes alone');

        // mark_verified: timestamp set, pending codes die.
        $svc->applyAdminUpdate($user, ['email_verification_action' => 'mark_verified']);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($pending->fresh()->used_at, 'explicit verification invalidates pending codes');

        // require_verification: timestamp cleared AND the obligation marker
        // imposed — the action must actually enforce, not just advertise.
        $svc->applyAdminUpdate($user, ['email_verification_action' => 'require_verification']);
        $fresh = $user->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertTrue((bool) $fresh->email_verification_required_at_registration);

        // A raw email_verified_at value in the payload is stripped, never
        // mass-assigned.
        $svc->applyAdminUpdate($user, ['email_verified_at' => now()->subYear(), 'email_verification_action' => 'keep']);
        $this->assertNull($user->fresh()->email_verified_at, 'no silent DateTimePicker-style assignment survives');
    }

    public function test_last_failed_attempt_retires_the_code_and_frees_the_cooldown(): void
    {
        SiteSetting::set('email_otp_max_attempts', 2);
        SiteSetting::set('email_otp_resend_cooldown_seconds', 3600);
        $svc = app(EmailVerificationService::class);
        $user = $this->unverifiedUser();
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        $this->assertSame('invalid', $svc->verify($user, '000000')['status']);
        // The LAST permitted wrong guess retires the code on the spot.
        $this->assertSame('too_many_attempts', $svc->verify($user, '000000')['status']);

        $fresh = $record->fresh();
        $this->assertNotNull($fresh->used_at, 'exhausted codes are consumed, never left actionable');
        // Freed: no advertised lifetime, no hour-long cooldown strand.
        $this->assertNull($svc->activeCodeRemainingMinutes($user->fresh()));
        $this->assertTrue($svc->canResend($user->fresh()), 'a replacement can be requested immediately');
    }

    public function test_admin_require_verification_actually_enforces_for_unobligated_accounts(): void
    {
        // An account created OUTSIDE required mode (marker false) that the
        // middleware would normally bypass forever.
        $user = User::factory()->create(['email' => 'exempt.user@example.com', 'email_verified_at' => null]);
        $this->assertFalse((bool) $user->email_verification_required_at_registration);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        app(EmailVerificationService::class)->applyAdminUpdate($user, [
            'email_verification_action' => 'require_verification',
        ]);

        // The admin's explicit demand imposes the per-user obligation: with
        // global enforcement active (setUp), the dashboard is now gated.
        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->email_verification_required_at_registration);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('verification.notice'));

        // OPTIONAL mode (registration-wide toggle off) does NOT release an
        // imposed obligation — that toggle only governs stamping of NEW
        // registrations. The mail fail-safes still do: disabling the feature
        // (or losing the transport proof) always unblocks.
        SiteSetting::set('email_verification_required_on_register', 'false');
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('verification.notice'));

        SiteSetting::set('email_verification_enabled', 'false');
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($fresh)->get('/dashboard')->assertOk();
    }

    public function test_changing_the_email_demands_an_explicit_verification_policy(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::factory()->create(['email' => 'policy.user@example.com', 'email_verified_at' => now()]);

        try {
            $svc->applyAdminUpdate($user, ['email' => 'policy.moved@example.com', 'email_verification_action' => 'keep']);
            $this->fail('keep must be refused for a CHANGED address');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.email_verification_action', $e->errors());
        }
        $this->assertSame('policy.user@example.com', $user->fresh()->email, 'nothing changed');

        // Explicit policies work for the changed address.
        $svc->applyAdminUpdate($user, ['email' => 'policy.moved@example.com', 'email_verification_action' => 'mark_verified']);
        $fresh = $user->fresh();
        $this->assertSame('policy.moved@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);

        $svc->applyAdminUpdate($user, ['email' => 'policy.again@example.com', 'email_verification_action' => 'require_verification']);
        $fresh = $user->fresh();
        $this->assertSame('policy.again@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_admin_create_applies_the_explicit_verification_state_atomically(): void
    {
        $page = new CreateUser;
        $method = new \ReflectionMethod($page, 'handleRecordCreation');

        // Verified creation persists the timestamp; the obligation marker is
        // DELIBERATELY false regardless of the current global policy.
        $verified = $method->invoke($page, [
            'name' => 'v', 'username' => 'admin_made_v',
            'email' => 'admin.verified@example.com', 'password' => Hash::make('irrelevant1'),
            'email_is_verified' => true, 'is_admin' => false,
        ]);
        $this->assertNotNull($verified->fresh()->email_verified_at);
        $this->assertFalse((bool) $verified->fresh()->email_verification_required_at_registration, 'admin-created users are never auto-obligated');

        // Unverified creation persists null — and is NOT blocked by the
        // middleware (marker false), matching the documented policy.
        $unverified = $method->invoke($page, [
            'name' => 'u', 'username' => 'admin_made_u',
            'email' => 'admin.unverified@example.com', 'password' => Hash::make('irrelevant1'),
            'email_is_verified' => false, 'is_admin' => false,
        ]);
        $this->assertNull($unverified->fresh()->email_verified_at);
        $this->actingAs($unverified->fresh())->get('/dashboard')->assertOk();

        // A collision rolls the WHOLE creation back — no partial user with
        // is_admin/verification applied survives.
        try {
            $method->invoke($page, [
                'name' => 'x', 'username' => 'admin_made_dup',
                'email' => 'Admin.VERIFIED@example.com', 'password' => Hash::make('irrelevant1'),
                'email_is_verified' => true, 'is_admin' => true,
            ]);
            $this->fail('the email collision must surface as a field error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.email', $e->errors());
        }
        $this->assertSame(0, User::where('username', 'admin_made_dup')->count(), 'no partially created user survives');
    }

    // ── Item 10: registration transaction ────────────────────────────────────

    public function test_failed_registration_rolls_back_user_and_sends_no_notifications(): void
    {
        Queue::fake();
        $this->mock(ReferralService::class, function ($mock) {
            $mock->shouldReceive('attachReferrer')->andThrow(new \RuntimeException('referral write failed'));
        });
        $telegram = $this->mock(TelegramAdminNotifier::class);
        $telegram->shouldNotReceive('event');

        $this->post('/register', $this->registrationPayload(['email' => 'rolledback@example.com']))
            ->assertStatus(500);

        // The WHOLE registration rolled back: no user, no codes, no jobs.
        $this->assertNull(User::where('email', 'rolledback@example.com')->first());
        $this->assertSame(0, EmailVerificationCode::count());
        Queue::assertNothingPushed();
        $this->assertGuest();
    }

    public function test_successful_registration_notifies_exactly_once_after_commit(): void
    {
        Mail::fake();
        $telegram = $this->mock(TelegramAdminNotifier::class);
        $telegram->shouldReceive('event')->once()->withArgs(
            fn (string $event) => $event === 'user_registered'
        );

        $this->post('/register', $this->registrationPayload(['email' => 'committed@example.com']))
            ->assertRedirect(route('verification.notice'));

        $this->assertNotNull(User::where('email', 'committed@example.com')->first());
    }
}
