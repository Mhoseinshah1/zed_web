<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Exception;
use Tests\TestCase;

/**
 * The queued OTP job's DATABASE lock order on real PostgreSQL: the job locks
 * the authoritative USER row first (exactly like requestCode/verify/
 * changeAddressTo), so a transaction OUTSIDE the cache-lock protocol —
 * commands, imports, maintenance tooling — holding the user row contends the
 * job into its bounded lock timeout: no email is sent, no partial state
 * commits, and the job is released for a retry rather than failed.
 * PostgreSQL-only; manages its own committed rows.
 */
class EmailJobLockOrderPgTest extends TestCase
{
    private const PREFIX = 'emailjlo_';

    /** Generous walltime bound: lock_timeout (2.5s) + cache wait + overhead. */
    private const MAX_SECONDS = 10;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real lock-order verification requires PostgreSQL (CI pgsql job).');
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

    public function test_job_contends_on_the_user_row_first_and_is_released_bounded(): void
    {
        Mail::fake();
        $user = User::create([
            'name' => 'J', 'username' => self::PREFIX.'user',
            'email' => self::PREFIX.'user@test.com', 'password' => bcrypt('x'),
        ]);
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        // Connection A: an EXTERNAL transaction (no cache lock) modifies and
        // holds ONLY the user row — it never touches the OTP row. The job
        // still contends, proving the user row is locked FIRST.
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->update('update users set name = ? where id = ?', ['External Edit', $user->id]);

        $started = microtime(true);
        try {
            (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
            $this->fail('without a queue context, contention must surface');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(Exception::class, $e, 'a PHPUnit failure must never be mistaken for the expected exception');
            // A real worker would be released for a delayed retry instead.
        }
        $elapsed = microtime(true) - $started;

        // Bounded wait, no send, no partial state, retry-friendly outcome.
        Mail::assertNothingSent();
        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'the job waits only up to the configured lock timeout');
        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $fresh->send_status, 'released for retry — never failed on contention');
        $this->assertNull($fresh->send_error);

        // Contention clears → the SAME job delivers exactly once.
        $blocker->rollBack();
        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
        Mail::assertSentCount(1);
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
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
