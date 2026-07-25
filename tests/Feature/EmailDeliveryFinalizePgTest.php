<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Delivery finalization races on REAL PostgreSQL with an INDEPENDENT second
 * connection: (1) a worker whose cache lock expired mid-send, while a
 * competing owner re-claims the record over the other connection, must not
 * overwrite the newer claim; (2) finalization row-lock contention is bounded
 * (two `SET LOCAL lock_timeout` attempts, then an honest sanitized log) and
 * never blocks indefinitely. PostgreSQL-only; manages its own committed rows.
 */
class EmailDeliveryFinalizePgTest extends TestCase
{
    private const PREFIX = 'emailfin_';

    /** Walltime bound: 2 × lock_timeout (2.5s) + cache wait + overhead. */
    private const MAX_SECONDS = 15;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real finalization races require PostgreSQL (CI pgsql job).');
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

    public function test_expired_lock_worker_cannot_overwrite_a_claim_taken_over_another_connection(): void
    {
        $user = $this->makeUser('race');
        $record = $this->makeQueued($user);
        $lockKey = EmailVerificationService::userLockKey($user->id);
        $foreignToken = bin2hex(random_bytes(32));

        // Mid-send: worker A's cache lock "expires"; worker B — over the
        // INDEPENDENT connection and now holding the cache lock — re-claims
        // the record under its own token and even finalizes it as sent.
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use ($lockKey, $record, $foreignToken) {
            Cache::lock($lockKey)->forceRelease();
            $this->assertTrue(Cache::lock($lockKey, 60)->get());
            DB::connection('pgsql_blocker')->update(
                'update email_verification_codes set send_status = ?, delivery_claim_token = ?, delivery_claimed_at = now() where id = ?',
                [EmailVerificationCode::SEND_STATUS_SENDING, $foreignToken, $record->id],
            );
        });
        Mail::shouldReceive('to')->andReturn($pending);

        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();

        // Worker A touched NOTHING: B's claim (and any state B records) is
        // preserved exactly as B left it.
        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $fresh->send_status);
        $this->assertSame($foreignToken, $fresh->delivery_claim_token, 'the newer claim is untouchable');
        Cache::lock($lockKey)->forceRelease();
    }

    public function test_finalization_row_lock_contention_is_bounded_and_leaves_the_claim_for_retry(): void
    {
        $user = $this->makeUser('bound');
        $record = $this->makeQueued($user);

        // Mid-send (AFTER the claim committed), the independent connection
        // grabs the OTP row FOR UPDATE — both finalization attempts (sent,
        // then the accepted_pending fallback) must time out bounded. The
        // SECOND send (the retry, after contention clears) is a plain no-op.
        $sendCalls = 0;
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->twice()->andReturnUsing(function () use ($record, &$sendCalls) {
            if (++$sendCalls === 1) {
                $blocker = DB::connection('pgsql_blocker');
                $blocker->beginTransaction();
                $blocker->select('select id from email_verification_codes where id = ? for update', [$record->id]);
            }
        });
        Mail::shouldReceive('to')->twice()->andReturn($pending);

        $started = microtime(true);
        (new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10))->handle();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(self::MAX_SECONDS, $elapsed, 'finalization never waits indefinitely');
        // Honest outcome: transport accepted, finalization uncertain — the
        // record stays `sending` under this attempt's token (a retry may
        // re-send: the documented at-least-once residual).
        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $fresh->send_status);
        $this->assertNotNull($fresh->delivery_claim_token);

        // Contention clears → a retry re-claims and completes normally.
        DB::connection('pgsql_blocker')->rollBack();
        $retryContext = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $retryContext->shouldReceive('attempts')->andReturn(2);
        $retry = new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10);
        $retry->setJob($retryContext);
        $retry->handle();
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
