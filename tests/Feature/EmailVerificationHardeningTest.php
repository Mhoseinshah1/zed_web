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
        return User::factory()->create(array_merge(['email_verified_at' => null], $attrs));
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
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENDING,
        ]);

        (new SendEmailOtpJob($record->id, (string) $user->email, '123456', 10))
            ->failed(new \RuntimeException('535 Authentication failed: Authorization: Basic dXNlcjpwYXNz with password "TopSecret42"'));

        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $record->send_status);
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

        return [$record, new SendEmailOtpJob($record->id, (string) $user->email, $code, 10)];
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

    // ── Grandfathering: required mode never locks out earlier cohorts ────────

    public function test_users_created_while_optional_are_grandfathered_when_required_is_enabled(): void
    {
        SiteSetting::set('email_verification_required_on_register', 'false');
        $legacy = $this->unverifiedUser();
        $this->travel(2)->minutes();

        // The admin enables required mode THROUGH the settings page (which
        // records the transition moment); the proof already exists (setUp).
        Livewire::actingAs($this->admin())
            ->test(EmailSettingsPage::class)
            ->fillForm([
                'email_verification_enabled' => true,
                'email_verification_required_on_register' => true,
            ])
            ->call('save');
        $this->assertTrue(app(EmailVerificationService::class)->isRequiredOnRegister());

        // The pre-existing unverified account keeps full access…
        $this->actingAs($legacy)->get('/dashboard')->assertOk();

        // …while accounts created AFTER the transition are enforced.
        $this->travel(1)->minutes();
        $fresh = $this->unverifiedUser();
        $this->actingAs($fresh)->get('/dashboard')->assertRedirect(route('verification.notice'));
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
            $method->invoke($page, $user, ['email' => 'HELD@Example.COM', 'email_change_mark_verified' => true]);
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

        (new SendEmailOtpJob($record->id, (string) $user->email, '123456', 10))->handle();

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

        (new SendEmailOtpJob($record->id, (string) $user->email, '123456', 10))->handle();

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
            (new SendEmailOtpJob($record->id, (string) $user->email, '123456', 10))->handle();
        } finally {
            $lock->release();
        }

        Mail::assertNothingSent();
        // Contention is NOT a delivery failure: still queued for the retry.
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

        (new SendEmailOtpJob($record->id, (string) $user->email, '123456', 10))
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
