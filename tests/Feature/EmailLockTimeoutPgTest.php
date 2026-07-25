<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Support\DatabaseLockTimeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bounded PostgreSQL row-lock waiting: a SECOND real connection holds the
 * user/code rows FOR UPDATE (outside the application's cache-lock protocol),
 * and every email-verification transaction must give up within the configured
 * `SET LOCAL lock_timeout`, produce the controlled Persian busy result with
 * ZERO partial changes and no raw database text, and succeed normally once
 * the contention clears. PostgreSQL-only; manages its own committed rows
 * (RefreshDatabase's wrapping transaction would hide them from the second
 * connection).
 */
class EmailLockTimeoutPgTest extends TestCase
{
    private const PREFIX = 'emaillock_';

    /** Generous walltime bound: lock_timeout (2.5s) + cache wait + overhead. */
    private const MAX_SECONDS = 10;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock timeout behavior requires PostgreSQL (CI pgsql job).');
        }

        $this->cleanup();
        SiteSetting::set('email_verification_enabled', 'true');

        config(['database.connections.pgsql_blocker' => config('database.connections.'.config('database.default'))]);
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::connection('pgsql_blocker')->rollBack();
            } catch (\Throwable) {
            }
            DB::purge('pgsql_blocker');
            $this->cleanup();
            SiteSetting::set('email_verification_enabled', 'false');
        }
        parent::tearDown();
    }

    private function makeUser(string $suffix): User
    {
        return User::create([
            'name' => 'L', 'username' => self::PREFIX.$suffix,
            'email' => self::PREFIX.$suffix.'@test.com',
            'password' => bcrypt('x'), 'email_verified_at' => null,
        ]);
    }

    /** Hold the given users row FOR UPDATE from an independent connection. */
    private function blockUserRow(User $user): void
    {
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from users where id = ? for update', [$user->id]);
    }

    private function releaseBlocker(): void
    {
        DB::connection('pgsql_blocker')->rollBack();
    }

    public function test_issuance_times_out_bounded_with_no_partial_changes(): void
    {
        $user = $this->makeUser('issue');
        $this->blockUserRow($user);

        $started = microtime(true);
        $result = app(EmailVerificationService::class)->requestCode($user);
        $elapsed = microtime(true) - $started;

        $this->assertSame('busy', $result['status']);
        $this->assertSame(EmailVerificationService::BUSY_MESSAGE, $result['message']);
        $this->assertStringNotContainsString('SQLSTATE', $result['message']);
        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'no request may wait indefinitely');
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->count(), 'no partial changes');

        // Uncontended operation succeeds immediately afterwards.
        $this->releaseBlocker();
        $this->assertSame('queued', app(EmailVerificationService::class)->requestCode($user)['status']);
    }

    public function test_verification_times_out_bounded(): void
    {
        $user = $this->makeUser('verify');
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        $this->blockUserRow($user);

        $started = microtime(true);
        $result = app(EmailVerificationService::class)->verify($user, '123456');
        $elapsed = microtime(true) - $started;

        $this->assertSame('busy', $result['status']);
        $this->assertSame(EmailVerificationService::BUSY_MESSAGE, $result['message']);
        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
        $this->assertNull(User::find($user->id)->email_verified_at, 'no partial verification');

        $this->releaseBlocker();
        $this->assertSame('verified', app(EmailVerificationService::class)->verify($user, '123456')['status']);
    }

    public function test_address_change_times_out_bounded_with_nothing_changed(): void
    {
        $user = $this->makeUser('change');
        $before = (string) $user->email;
        $this->blockUserRow($user);

        $started = microtime(true);
        $changed = app(EmailVerificationService::class)->changeAddressTo($user, self::PREFIX.'new@test.com');
        $elapsed = microtime(true) - $started;

        $this->assertFalse($changed, 'contention yields the controlled retry path');
        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
        $this->assertSame($before, (string) User::find($user->id)->email, 'nothing changed');

        $this->releaseBlocker();
        $this->assertTrue(app(EmailVerificationService::class)->changeAddressTo($user, self::PREFIX.'new@test.com'));
    }

    public function test_job_claim_contention_releases_without_failing_or_sending(): void
    {
        Mail::fake();
        $user = $this->makeUser('job');
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        // Hold the CODE row this time — the claim locks it after the cache lock.
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from email_verification_codes where id = ? for update', [$record->id]);

        $started = microtime(true);
        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
        $elapsed = microtime(true) - $started;

        Mail::assertNothingSent();
        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
        // Contention is NOT a delivery failure: the record stays queued for
        // the released job's retry.
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $record->fresh()->send_status);
        $this->assertNull($record->fresh()->send_error);

        $this->releaseBlocker();
        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
    }

    public function test_lock_timeout_constant_is_the_documented_bound(): void
    {
        $this->assertSame(2500, DatabaseLockTimeout::LOCK_TIMEOUT_MS);
    }

    private function cleanup(): void
    {
        try {
            $userIds = DB::table('users')->where('username', 'like', self::PREFIX.'%')->pluck('id');
            if ($userIds->isNotEmpty()) {
                DB::table('email_verification_codes')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}
