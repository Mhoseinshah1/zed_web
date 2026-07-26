<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Support\DatabaseLockTimeout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Queue-publication health race on REAL PostgreSQL with an INDEPENDENT
 * second connection: while a publication-metadata transaction is still in
 * flight (uncommitted), a concurrent transportLooksLive() must neither block
 * on it nor treat the merely-created queued row as publication-recovery
 * evidence — only the COMMITTED queue_published_at stamp counts.
 * PostgreSQL-only; manages its own committed rows.
 */
class EmailQueuePublicationPgTest extends TestCase
{
    private const PREFIX = 'emailpub_';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The publication health race requires PostgreSQL (CI pgsql job).');
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

    public function test_health_ignores_an_uncommitted_publication_stamp_and_never_blocks_on_it(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::create([
            'name' => 'P', 'username' => self::PREFIX.'race',
            'email' => self::PREFIX.'race@test.com', 'password' => bcrypt('x'),
        ]);

        // A REAL publication failure inside the window…
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            'send_error' => 'dispatch failed: queue down',
            'delivery_finalized_at' => now()->subMinute(),
            'delivery_config_fingerprint' => $svc->mailConfigFingerprint(),
        ]);

        // …and a replacement issuance whose publication-metadata write is
        // STILL IN FLIGHT on the independent connection (uncommitted).
        $replacement = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->update(
            'update email_verification_codes set queue_published_at = now() where id = ?',
            [$replacement->id],
        );

        // The health check must complete promptly (its reads never wait on
        // the writer's row lock) and must NOT report recovery: the row
        // exists, is queued, and is newer than the failure — but no
        // publication has been CONFIRMED.
        $started = microtime(true);
        $live = $svc->transportLooksLive();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(5, $elapsed, 'health reads never block behind the metadata writer');
        $this->assertFalse($live, 'an in-flight (uncommitted) publication stamp is not recovery evidence');
        $this->assertFalse($svc->isEnforceableNow());

        // The metadata transaction COMMITS: the confirmed publication now
        // postdates the newest failure — recovery is proven.
        $blocker->commit();
        $this->assertTrue($svc->transportLooksLive(), 'the committed publication stamp proves recovery');
    }

    public function test_a_worker_claim_between_dispatch_exception_and_finalization_is_never_overwritten(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::create([
            'name' => 'P', 'username' => self::PREFIX.'claim',
            'email' => self::PREFIX.'claim@test.com', 'password' => bcrypt('x'),
        ]);

        // The delivered code this resend superseded (consumed by issuance).
        $delivered = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        $replacement = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        // The producer's dispatch() threw AFTER the push secretly reached
        // the broker: its last view of the row is `queued` — but a fast
        // worker claims it (over the independent connection) before the
        // failure handler runs.
        $staleView = $replacement->fresh();
        $workerToken = bin2hex(random_bytes(32));
        DB::connection('pgsql_blocker')->update(
            'update email_verification_codes set send_status = ?, delivery_claim_token = ?, delivery_claimed_at = now() where id = ?',
            [EmailVerificationCode::SEND_STATUS_SENDING, $workerToken, $replacement->id],
        );

        $handle = new \ReflectionMethod($svc, 'handleDispatchFailure');
        $handle->invoke($svc, $staleView, $delivered->id, new \RuntimeException('queue push timeout'));

        // The failure handler re-read under the full lock protocol, saw the
        // worker's claim, and touched NOTHING: no dispatch_failed overwrite,
        // no consumption, and NO restore while the worker can still deliver.
        $fresh = $replacement->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $fresh->send_status, 'the claim survives');
        $this->assertSame($workerToken, $fresh->delivery_claim_token, 'the worker token is untouched');
        $this->assertNull($fresh->used_at, 'the replacement stays live for the worker');
        $this->assertNotNull($delivered->fresh()->used_at, 'the superseded code is NOT restored');
    }

    /**
     * THE real production interleaving, with exactly ONE metadata write:
     *
     *   1. publication A succeeds — its handoff time T1 is captured;
     *   2. A's one and only recordQueuePublication() invocation (a forked
     *      child process on a fresh connection) BLOCKS on the held row lock
     *      (proven via pg_stat_activity, not sleeps);
     *   3. publication B fails and finalizes dispatch_failed at T2 > T1;
     *   4. the blocker releases BEFORE the 2500 ms SET LOCAL lock_timeout;
     *   5. the original invocation resumes and commits — storing T1, the
     *      captured handoff time, never the lock-release moment;
     *   6. T1 < T2, so the resumed stamp cannot masquerade as recovery.
     */
    public function test_a_blocked_publication_stamp_resumes_before_timeout_and_stores_the_captured_handoff_time(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the fork-based race test.');
        }

        $svc = app(EmailVerificationService::class);
        $user = User::create([
            'name' => 'P', 'username' => self::PREFIX.'stamp',
            'email' => self::PREFIX.'stamp@test.com', 'password' => bcrypt('x'),
        ]);

        // Publication A succeeded; T1 is captured BEFORE the metadata
        // mutation starts (requestCode() captures it right after dispatch()).
        $published = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        $handoffAtT1 = now()->subSeconds(10);

        // The blocker holds A's row; the parent works over a SEPARATE
        // observer connection resolved AFTER the fork boundary is set up,
        // because the child's connection purge terminates the connection it
        // inherited from the parent.
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from email_verification_codes where id = ? for update', [$published->id]);
        config(['database.connections.pgsql_observer' => config('database.connections.'.config('database.default'))]);

        // ONE child process = A's one and only metadata write.
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            DB::purge();
            DB::reconnect();
            try {
                app('cache')->forgetDriver((string) config('cache.default'));
            } catch (\Throwable) {
                // Array store — nothing to purge.
            }
            try {
                $record = new \ReflectionMethod($svc, 'recordQueuePublication');
                $record->invoke($svc, $published->id, $handoffAtT1);
                exit(0);
            } catch (\Throwable) {
                exit(9);
            }
        }

        $observer = DB::connection('pgsql_observer');

        // DETERMINISTIC proof the child's UPDATE is waiting on the row lock:
        // PostgreSQL lock-state observation, never a bare sleep.
        $deadline = microtime(true) + 8.0;
        $childBlocked = false;
        while (microtime(true) < $deadline) {
            $waiting = $observer->selectOne(
                "select count(*) as c from pg_stat_activity where wait_event_type = 'Lock' and state = 'active' and query ilike '%update%email_verification_codes%'"
            );
            if ((int) $waiting->c > 0) {
                $childBlocked = true;
                break;
            }
            usleep(10_000);
        }
        $this->assertTrue($childBlocked, 'the one metadata write is observably blocked on the row lock');

        // While A's write waits: publication B FAILS and finalizes at T2.
        $failedAtT2 = now();
        $this->assertTrue($failedAtT2->gt($handoffAtT1), 'T2 is strictly later than T1');
        EmailVerificationCode::on('pgsql_observer')->create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            'send_error' => 'dispatch failed: queue down',
            'delivery_finalized_at' => $failedAtT2,
            'delivery_config_fingerprint' => $svc->mailConfigFingerprint(),
        ]);

        // Release WELL BEFORE the 2500 ms lock timeout: the original write
        // resumes and commits.
        $blocker->rollBack();
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status), 'the blocked metadata write resumed and committed successfully');
        DB::reconnect();

        // It stored T1 — the captured handoff time — not the release moment…
        $this->assertSame(
            $handoffAtT1->format('Y-m-d H:i:s'),
            $published->fresh()->queue_published_at?->format('Y-m-d H:i:s'),
            'the resumed stamp records the true handoff moment',
        );
        // …so a pre-outage publication can never masquerade as recovery.
        $this->assertFalse(
            $svc->transportLooksLive(),
            'the resumed pre-outage stamp (T1 < T2) cannot clear the later publication failure',
        );

        DB::purge('pgsql_observer');
    }

    public function test_a_publication_stamp_lock_timeout_is_bounded_and_changes_nothing(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::create([
            'name' => 'P', 'username' => self::PREFIX.'bound',
            'email' => self::PREFIX.'bound@test.com', 'password' => bcrypt('x'),
        ]);
        $published = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);

        // The blocker stays held BEYOND the local lock timeout.
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from email_verification_codes where id = ? for update', [$published->id]);

        $record = new \ReflectionMethod($svc, 'recordQueuePublication');
        $started = microtime(true);
        try {
            $record->invoke($svc, $published->id, now()->subSeconds(10));
            $this->fail('the blocked metadata write must time out bounded');
        } catch (QueryException $e) {
            $this->assertTrue(
                DatabaseLockTimeout::isLockTimeout($e),
                'the failure is specifically the PostgreSQL lock timeout (55P03)',
            );
        }
        $elapsed = microtime(true) - $started;
        // 2500 ms SET LOCAL lock_timeout + a fixed CI margin.
        $this->assertLessThan(5.0, $elapsed, 'the metadata wait is bounded by the configured lock timeout');

        $blocker->rollBack();
        // The failed stamp changed NOTHING: metadata stays NULL (fail-open),
        // the delivery state is untouched, and no publication is retried.
        $fresh = $published->fresh();
        $this->assertNull($fresh->queue_published_at, 'queue_published_at remains NULL after the bounded timeout');
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $fresh->send_status, 'delivery status is untouched');
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
