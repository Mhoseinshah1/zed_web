<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Exception as PhpUnitException;
use RuntimeException;
use Tests\TestCase;

/**
 * Transport-FAILURE finalization on REAL PostgreSQL with an INDEPENDENT
 * second connection: failure bookkeeping (failTransportAttempt and the
 * failed() callback) uses the same bounded protocol as acceptance — short
 * transaction, SET LOCAL lock_timeout, User row then OTP row, timing-safe
 * claim-token check — so an unrelated transaction holding either row can
 * delay it only by the bounded wait, no worker ever hangs until the
 * database's default timeout, and no raw SQL/SMTP text ever escapes.
 * PostgreSQL-only; manages its own committed rows.
 */
class EmailFailureFinalizePgTest extends TestCase
{
    private const PREFIX = 'emailffin_';

    /** Walltime bound: lock_timeout (2.5s) + cache wait + overhead. */
    private const MAX_SECONDS = 15;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real failure-finalization races require PostgreSQL (CI pgsql job).');
        }

        $this->cleanup();
        SiteSetting::set('email_verification_enabled', 'true');
        config(['database.connections.pgsql_blocker' => config('database.connections.'.config('database.default'))]);
        try {
            Cache::flush();
        } catch (\Throwable) {
        }
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
            'name' => 'F', 'username' => self::PREFIX.$suffix,
            'email' => self::PREFIX.$suffix.'@test.com', 'password' => bcrypt('x'),
        ]);
    }

    private function makeQueued(User $user): EmailVerificationCode
    {
        return EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
    }

    /** Run one direct (final-attempt) delivery whose transport throws after $during. */
    private function runFailingSend(User $user, EmailVerificationCode $record, callable $during): RuntimeException
    {
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use ($during) {
            $during();

            throw new RuntimeException('SMTP error: could not authenticate with password "hunter2"');
        });
        Mail::shouldReceive('to')->andReturn($pending);

        try {
            (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
            $this->fail('the sanitized transport failure must propagate');
        } catch (RuntimeException $e) {
            // AssertionFailedError IS a RuntimeException, so without this the
            // fail() above would be captured and RETURNED as if it were the
            // subject's exception — the caller would then assert against our
            // own failure text.
            $this->assertNotInstanceOf(PhpUnitException::class, $e, 'a PHPUnit failure must never be returned as the expected exception');

            return $e;
        }
    }

    public function test_failure_finalization_waits_bounded_when_the_otp_row_is_held_elsewhere(): void
    {
        $user = $this->makeUser('row');
        $record = $this->makeQueued($user);

        $started = microtime(true);
        $e = $this->runFailingSend($user, $record, function () use ($record) {
            $blocker = DB::connection('pgsql_blocker');
            $blocker->beginTransaction();
            $blocker->select('select id from email_verification_codes where id = ? for update', [$record->id]);
        });
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'failure bookkeeping never waits indefinitely');
        $this->assertStringStartsWith('delivery failed:', $e->getMessage(), 'sanitized category only');
        $this->assertStringNotContainsString('hunter2', $e->getMessage(), 'no raw transport text');
        $this->assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'no raw database error');

        // No unbounded/lock-free fallback UPDATE: the row stays `sending`
        // under this attempt's claim for failed() to clean up later.
        DB::connection('pgsql_blocker')->rollBack();
        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $fresh->send_status);
        $this->assertNotNull($fresh->delivery_claim_token);
        $this->assertNull($fresh->delivery_finalized_at);
    }

    public function test_failure_finalization_waits_bounded_when_the_user_row_is_held_elsewhere(): void
    {
        $user = $this->makeUser('usr');
        $record = $this->makeQueued($user);

        $started = microtime(true);
        $this->runFailingSend($user, $record, function () use ($user) {
            $blocker = DB::connection('pgsql_blocker');
            $blocker->beginTransaction();
            $blocker->select('select id from users where id = ? for update', [$user->id]);
        });
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'the user-row lock wait is bounded too');
        DB::connection('pgsql_blocker')->rollBack();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $record->fresh()->send_status);
    }

    public function test_an_older_workers_failure_cannot_mark_a_newer_claim_failed(): void
    {
        $user = $this->makeUser('tok');
        $record = $this->makeQueued($user);
        $foreignToken = bin2hex(random_bytes(32));

        // Mid-send a NEWER worker (over the independent connection) re-claims
        // the record under its own token; the old worker's transport then
        // fails — its failure path must not touch token B's claim.
        $this->runFailingSend($user, $record, function () use ($record, $foreignToken) {
            DB::connection('pgsql_blocker')->update(
                'update email_verification_codes set delivery_claim_token = ?, delivery_claimed_at = now() where id = ?',
                [$foreignToken, $record->id],
            );
        });

        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $fresh->send_status, 'the newer claim survives');
        $this->assertSame($foreignToken, $fresh->delivery_claim_token, 'token B is untouchable by token A');
        $this->assertNull($fresh->delivery_finalized_at);
    }

    public function test_matching_token_finalizes_failed_exactly_once_with_a_sanitized_error(): void
    {
        $user = $this->makeUser('ok');
        $record = $this->makeQueued($user);

        $this->runFailingSend($user, $record, function () {});

        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $fresh->send_status);
        $this->assertNull($fresh->delivery_claim_token, 'claim cleared on the terminal transition');
        $this->assertNotNull($fresh->delivery_finalized_at);
        $this->assertNotNull($fresh->transport_attempted_at, 'a real transport attempt was recorded');
        $this->assertStringNotContainsString('hunter2', (string) $fresh->send_error, 'stored error is sanitized');
    }

    public function test_failed_callback_leaves_the_row_unchanged_when_locks_are_held_elsewhere(): void
    {
        $user = $this->makeUser('fcb');
        $record = $this->makeQueued($user);

        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from email_verification_codes where id = ? for update', [$record->id]);

        $started = microtime(true);
        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))
            ->failed(new RuntimeException('delivery failed: lock_contention (no retry available on the current queue driver)'));
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'failed() cleanup is bounded');
        $blocker->rollBack();
        // No unsafe lock-free mutation happened: the row is exactly as it was.
        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $fresh->send_status);
        $this->assertNull($fresh->delivery_finalized_at);
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
