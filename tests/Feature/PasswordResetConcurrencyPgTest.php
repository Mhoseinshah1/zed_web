<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Email\EmailVerificationService;
use App\Services\Sms\SmsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Tests\TestCase;

/**
 * REAL concurrent finalization of one authorized password-reset challenge on
 * PostgreSQL: the row locks + fingerprint revalidation are the arbiter —
 * two parallel submissions must end with exactly ONE password change, one
 * consumed authorization, and no double remember-token/credential rotation.
 *
 * ══ WHAT THIS CLASS DOES AND DOES NOT PROVE ═══════════════════════════════
 *
 * PROVES: the PostgreSQL side of the coordination — row-lock ordering,
 * conditional/monotonic updates, the password_reset_one_active invariant, and
 * the cross-flow interleavings, driven deterministically by file barriers.
 *
 * DOES NOT PROVE: the behavior of the production cache lock. These tests
 * override the cache store to `file` (the suite default `array` store lives
 * in ONE process's memory, so a fork could never observe another's lock), and
 * a file lock is a DIFFERENT implementation from Redis — different
 * acquisition, lease and release semantics. Redis lock coordination is proven
 * separately, against the real service, by PasswordResetRedisLockTest.
 *
 * No RefreshDatabase: forked children open FRESH connections, so fixtures
 * must be COMMITTED. Cleanup is explicit in tearDown. Children report through
 * per-child files and terminate with SIGKILL (a plain exit() would run
 * PHPUnit's shutdown handlers inside the fork).
 */
class PasswordResetConcurrencyPgTest extends TestCase
{
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real reset races require PostgreSQL (CI pgsql job).');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run real parallel submissions.');
        }

        // The per-user coordination lock must be REAL across processes, so
        // these deterministic interleavings use the shared FILE store rather
        // than the per-process `array` default. This is a STAND-IN, not
        // evidence about production: Redis is a different lock
        // implementation, and PasswordResetRedisLockTest proves it directly.
        config(['cache.default' => 'file']);
        Cache::store('file')->clear();

        PgBarrierResetService::$onBarrier = null;
        PgBarrierDeliveryJob::$onBeforeClaim = null;
        PgBarrierDeliveryJob::$onBeforeTransport = null;
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            try {
                Cache::store('file')->clear();
            } catch (\Throwable) {
                // best effort
            }
        }

        if (DB::getDriverName() === 'pgsql' && $this->userId !== null) {
            PasswordResetChallenge::where('user_id', $this->userId)->delete();
            User::whereKey($this->userId)->delete();
        }

        parent::tearDown();
    }

    /** File barrier: publish a named milestone for the other process. */
    private function signal(string $dir, string $name): void
    {
        file_put_contents($dir.'/'.$name, '1');
    }

    /**
     * Block until the other process publishes $name (bounded). A barrier —
     * not a timing sleep — is what makes each interleaving deterministic:
     * the poll interval only bounds how quickly the milestone is noticed,
     * never which ordering occurs.
     */
    private function await(string $dir, string $name, int $timeoutSeconds = 15): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($dir.'/'.$name)) {
                return true;
            }
            usleep(2000);
        }

        return false;
    }

    /** A shared barrier directory for one cross-flow race. */
    private function barrierDir(): string
    {
        $dir = sys_get_temp_dir().'/zp-pwreset-barrier-'.uniqid();
        mkdir($dir);

        return $dir;
    }

    /** Bind a service whose raceBarrier() runs $onBarrier (child-side). */
    private function bindBarrierService(\Closure $onBarrier): void
    {
        PgBarrierResetService::$onBarrier = $onBarrier;
        $this->app->instance(PasswordResetService::class, new PgBarrierResetService(
            app(EmailVerificationService::class),
            app(SmsService::class),
            app(Timebox::class),
        ));
    }

    /** An AUTHORIZED challenge ready for finalize(); returns [token, proof]. */
    private function makeAuthorizedChallenge(User $user): array
    {
        $token = bin2hex(random_bytes(32));
        $proof = bin2hex(random_bytes(32));
        PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => $token,
            'code_hash' => bcrypt('654321'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 1,
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENT,
            'authorized_at' => now(),
            'authorization_expires_at' => now()->addMinutes(10),
            'authorization_proof_hash' => hash('sha256', $proof),
            'password_fingerprint' => hash('sha256', (string) $user->password),
        ]);

        return [$token, $proof];
    }

    /**
     * Assert the invariants that must hold after ANY cross-flow interleaving
     * of replacement issuance and finalization.
     *
     * @param  string  $finalizeResult  'ok' (won) or 'lost' (documented safe loser)
     */
    private function assertCrossFlowInvariants(User $user, string $token, string $finalizeResult, string $winnerPassword): void
    {
        $fresh = User::findOrFail($user->id);
        $initial = User::INITIAL_AUTH_VERSION;

        if ($finalizeResult === 'ok') {
            $this->assertTrue(Hash::check($winnerPassword, $fresh->password), 'winner password applied');
            $this->assertSame($initial + 1, (int) $fresh->auth_version, 'auth_version advanced EXACTLY once');
        } else {
            $this->assertTrue(Hash::check('Old-Race-Pass-1', $fresh->password), 'password untouched by the loser');
            $this->assertSame($initial, (int) $fresh->auth_version, 'auth_version never advanced by a loser');
        }

        // Exactly one active challenge, and the raced one is never resurrected.
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
        $raced = PasswordResetChallenge::where('token', $token)->firstOrFail();
        $this->assertNotNull($raced->consumed_at, 'the raced challenge stays consumed');
    }

    /** Fork N children, run $work(childIndex), collect their reports. */
    private function race(int $children, callable $work): array
    {
        $dir = sys_get_temp_dir().'/zp-pwreset-fork-'.uniqid();
        mkdir($dir);

        $pids = [];
        foreach (range(1, $children) as $i) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                try {
                    $result = (string) $work($i);
                } catch (\Throwable $e) {
                    $result = 'crash:'.class_basename($e);
                }
                file_put_contents($dir.'/c'.$i, $result);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        DB::purge();
        DB::reconnect();
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach (range(1, $children) as $i) {
            $results[$i] = is_file($dir.'/c'.$i) ? trim((string) file_get_contents($dir.'/c'.$i)) : 'missing';
            @unlink($dir.'/c'.$i);
        }
        @rmdir($dir);

        return $results;
    }

    private function makeUser(): User
    {
        $user = User::factory()->create([
            'email' => 'race-'.uniqid().'@example.com',
            'password' => bcrypt('Old-Race-Pass-1'),
        ]);
        $this->userId = $user->id;

        return $user->refresh();
    }

    /** A committed ACTIVE challenge with a known code; returns [token, code]. */
    private function makeActiveChallenge(User $user, string $code = '654321'): string
    {
        $token = bin2hex(random_bytes(32));
        PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => $token,
            'code_hash' => bcrypt($code),
            'expires_at' => now()->addMinutes(10),
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENT,
        ]);

        return $token;
    }

    public function test_concurrent_issuance_leaves_exactly_one_active_challenge(): void
    {
        $user = $this->makeUser();

        // Two parallel request() calls through the REAL service. The public
        // path takes the per-user lock NON-BLOCKING (PUBLIC_LOCK_WAIT_SECONDS
        // = 0), so issuance is NOT fully serialized: a caller that loses the
        // lock does not queue behind the winner, it returns generically
        // without issuing. Whichever callers DO issue run supersede + create
        // atomically under the user-row lock.
        $results = $this->race(2, function () use ($user): string {
            $outcome = app(PasswordResetService::class)->request((string) $user->email);

            return (string) $outcome['outcome'];
        });

        $issued = count(array_filter(
            $results,
            static fn (string $outcome): bool => $outcome === PasswordResetService::OUTCOME_ISSUED,
        ));

        // At least one caller issues — contention must never leave the account
        // with no challenge at all — and a loser reports a NON-issued outcome
        // rather than blocking (whether both win depends on the interleaving:
        // a caller that finishes before the other starts is uncontended).
        $this->assertGreaterThanOrEqual(1, $issued, 'outcomes: '.implode(',', $results));
        foreach ($results as $outcome) {
            $this->assertContains($outcome, [
                PasswordResetService::OUTCOME_ISSUED,
                PasswordResetService::OUTCOME_DECOY,
            ], 'outcomes: '.implode(',', $results));
        }

        // Exactly ONE active challenge survives — the database-level partial
        // unique index (password_reset_one_active) guarantees it can never
        // be more — and only the issuing callers created a row.
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
        $this->assertSame($issued, PasswordResetChallenge::where('user_id', $user->id)->count());
    }

    public function test_database_invariant_rejects_a_second_active_challenge_outside_the_service(): void
    {
        $user = $this->makeUser();
        $this->makeActiveChallenge($user);

        // A raw second ACTIVE insert (bypassing the service and its locks)
        // hits the partial unique index.
        try {
            DB::transaction(function () use ($user): void {
                PasswordResetChallenge::create([
                    'user_id' => $user->id,
                    'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
                    'token' => bin2hex(random_bytes(32)),
                    'code_hash' => bcrypt('111111'),
                    'expires_at' => now()->addMinutes(10),
                ]);
            });
            $this->fail('second active challenge must violate password_reset_one_active');
        } catch (QueryException $e) {
            $this->assertStringContainsString('password_reset_one_active', $e->getMessage());
        }

        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    public function test_concurrent_correct_codes_produce_exactly_one_authorization(): void
    {
        $user = $this->makeUser();
        $token = $this->makeActiveChallenge($user, '654321');

        $results = $this->race(2, function () use ($token): string {
            $proof = app(PasswordResetService::class)->verifyCode($token, '654321');

            return $proof !== null ? 'authorized' : 'denied';
        });

        // Exactly one winner mints the authorization; the challenge is
        // authorized exactly once.
        $this->assertCount(1, array_keys($results, 'authorized'), 'winners: '.implode(',', $results));
        $this->assertCount(1, array_keys($results, 'denied'), 'losers: '.implode(',', $results));
        $challenge = PasswordResetChallenge::where('token', $token)->firstOrFail();
        $this->assertNotNull($challenge->authorized_at);
    }

    public function test_concurrent_wrong_codes_cannot_exceed_the_attempt_cap(): void
    {
        $user = $this->makeUser();
        $token = $this->makeActiveChallenge($user, '654321');

        // Seven parallel WRONG submissions serialize on the row lock: only
        // MAX_ATTEMPTS (5) increments can ever land.
        $this->race(7, function () use ($token): string {
            app(PasswordResetService::class)->verifyCode($token, '000000');

            return 'done';
        });

        $challenge = PasswordResetChallenge::where('token', $token)->firstOrFail();
        $this->assertSame(PasswordResetService::MAX_ATTEMPTS, (int) $challenge->attempts);

        // Even the CORRECT code is refused once the budget is exhausted.
        $this->assertNull(app(PasswordResetService::class)->verifyCode($token, '654321'));
        $this->assertNull($challenge->fresh()->authorized_at);
    }

    // ── Cross-flow: replacement issuance vs finalization (lock hierarchy) ────

    public function test_finalization_holding_its_locks_first_never_deadlocks_with_issuance(): void
    {
        $user = $this->makeUser();
        [$token, $proof] = $this->makeAuthorizedChallenge($user);
        $dir = $this->barrierDir();

        // Child 1 = finalize: parks INSIDE its transaction holding the user
        // (and about to take the challenge) lock. Child 2 = replacement
        // issuance, which starts only after that.
        $results = $this->race(2, function (int $i) use ($dir, $token, $proof, $user): string {
            if ($i === 1) {
                $this->bindBarrierService(function (string $stage) use ($dir): void {
                    if ($stage === 'finalize.locks_acquired') {
                        $this->signal($dir, 'finalize_locked');
                        $this->await($dir, 'issuance_started');
                    }
                });

                return app(PasswordResetService::class)->finalize($token, $proof, 'Cross-Flow-Pass-1') ? 'ok' : 'lost';
            }

            $this->await($dir, 'finalize_locked');
            $this->signal($dir, 'issuance_started');
            $issued = app(PasswordResetService::class)->request((string) $user->email);

            return $issued !== '' ? 'issued' : 'failed';
        });

        exec('rm -rf '.escapeshellarg($dir));

        // No deadlock, no crash: finalize wins (it held the locks first) and
        // issuance completes right behind it.
        $this->assertSame('ok', $results[1], 'children: '.implode(',', $results));
        $this->assertSame('issued', $results[2], 'children: '.implode(',', $results));
        $this->assertCrossFlowInvariants($user, $token, 'ok', 'Cross-Flow-Pass-1');
    }

    public function test_issuance_holding_the_lock_first_never_deadlocks_with_finalization(): void
    {
        $user = $this->makeUser();
        [$token, $proof] = $this->makeAuthorizedChallenge($user);
        $dir = $this->barrierDir();

        // Reverse ordering: child 1 = replacement issuance parked right after
        // taking the per-user coordination lock; child 2 = finalize.
        $results = $this->race(2, function (int $i) use ($dir, $token, $proof, $user): string {
            if ($i === 1) {
                $this->bindBarrierService(function (string $stage) use ($dir): void {
                    if ($stage === 'issue.lock_acquired') {
                        $this->signal($dir, 'issuance_locked');
                        $this->await($dir, 'finalize_started');
                    }
                });
                $issued = app(PasswordResetService::class)->request((string) $user->email);

                return $issued['outcome'] === PasswordResetService::OUTCOME_ISSUED ? 'issued' : 'failed';
            }

            $this->await($dir, 'issuance_locked');
            $this->signal($dir, 'finalize_started');

            return app(PasswordResetService::class)->finalize($token, $proof, 'Cross-Flow-Pass-2') ? 'ok' : 'lost';
        });

        exec('rm -rf '.escapeshellarg($dir));

        // ACCEPTED OUTCOMES (documented): issuance always completes; finalize
        // either won the user row first ('ok') or found its challenge already
        // superseded ('lost'). Both are correct — what must never happen is a
        // deadlock, a crash, a partial write, or a double auth_version bump.
        $this->assertSame('issued', $results[1], 'children: '.implode(',', $results));
        $this->assertContains($results[2], ['ok', 'lost'], 'children: '.implode(',', $results));
        $this->assertCrossFlowInvariants($user, $token, $results[2], 'Cross-Flow-Pass-2');
    }

    // ── Cross-flow: replacement issuance vs an in-flight delivery ────────────

    public function test_replacement_issuance_before_the_claim_stops_a_cross_process_stale_send(): void
    {
        $user = $this->makeUser();
        $old = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => bin2hex(random_bytes(32)),
            'code_hash' => bcrypt('654321'),
            'expires_at' => now()->addMinutes(10),
            'send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED,
        ]);
        $dir = $this->barrierDir();

        $results = $this->race(2, function (int $i) use ($dir, $old, $user): string {
            if ($i === 1) {
                // The old worker is parked BEFORE it can claim.
                PgBarrierDeliveryJob::$onBeforeClaim = function () use ($dir): void {
                    $this->signal($dir, 'worker_ready');
                    $this->await($dir, 'replacement_issued');
                };
                (new PgBarrierDeliveryJob($old->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                return (string) PasswordResetChallenge::whereKey($old->id)->value('send_status');
            }

            $this->await($dir, 'worker_ready');
            app(PasswordResetService::class)->request((string) $user->email);
            $this->signal($dir, 'replacement_issued');

            return 'issued';
        });

        exec('rm -rf '.escapeshellarg($dir));

        // The stale worker transported NOTHING: its challenge was superseded
        // before it could claim, so it never reached `sending`/`sent`.
        $this->assertSame('issued', $results[2], 'children: '.implode(',', $results));
        $this->assertNotSame(PasswordResetChallenge::SEND_STATUS_SENT, $results[1], 'children: '.implode(',', $results));
        $stale = PasswordResetChallenge::findOrFail($old->id);
        $this->assertNotNull($stale->consumed_at);
        $this->assertNotSame(PasswordResetChallenge::SEND_STATUS_SENT, $stale->send_status);
        $this->assertNull($stale->delivery_claim_token);
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    public function test_an_in_flight_claim_excludes_a_concurrent_replacement_issuance(): void
    {
        $user = $this->makeUser();
        $old = PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => bin2hex(random_bytes(32)),
            'code_hash' => bcrypt('654321'),
            'expires_at' => now()->addMinutes(10),
            'send_status' => PasswordResetChallenge::SEND_STATUS_QUEUED,
        ]);
        $dir = $this->barrierDir();

        $results = $this->race(2, function (int $i) use ($dir, $old, $user): string {
            if ($i === 1) {
                // The worker WINS the claim and parks mid-transport while
                // holding the per-user coordination lock.
                PgBarrierDeliveryJob::$onBeforeTransport = function () use ($dir): void {
                    $this->signal($dir, 'transport_started');
                    $this->await($dir, 'issuance_attempted');
                };
                (new PgBarrierDeliveryJob($old->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                return (string) PasswordResetChallenge::whereKey($old->id)->value('send_status');
            }

            $this->await($dir, 'transport_started');
            $outcome = app(PasswordResetService::class)->request((string) $user->email);
            $this->signal($dir, 'issuance_attempted');

            return PasswordResetChallenge::where('token', $outcome['token'])->exists() ? 'replaced' : 'refused';
        });

        exec('rm -rf '.escapeshellarg($dir));

        // The in-flight attempt completed exactly once, and the concurrent
        // replacement could NOT become authoritative mid-flight. It is
        // EXCLUDED, not serialized: the public path does not queue behind the
        // lock holder, it returns a decoy immediately (public response
        // unchanged) and creates no row.
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $results[1], 'children: '.implode(',', $results));
        $this->assertSame('refused', $results[2], 'children: '.implode(',', $results));
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->count());
        $this->assertNull(PasswordResetChallenge::findOrFail($old->id)->consumed_at);
    }

    public function test_concurrent_finalizations_produce_exactly_one_password_change(): void
    {
        $user = User::factory()->create([
            'email' => 'race-'.uniqid().'@example.com',
            'password' => bcrypt('Old-Race-Pass-1'),
        ]);
        $this->userId = $user->id;

        $token = bin2hex(random_bytes(32));
        $proof = bin2hex(random_bytes(32));
        PasswordResetChallenge::create([
            'user_id' => $user->id,
            'channel' => PasswordResetChallenge::CHANNEL_EMAIL,
            'token' => $token,
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 1,
            'send_status' => PasswordResetChallenge::SEND_STATUS_SENT,
            'authorized_at' => now(),
            'authorization_expires_at' => now()->addMinutes(10),
            'authorization_proof_hash' => hash('sha256', $proof),
            'password_fingerprint' => hash('sha256', (string) $user->password),
        ]);

        $dir = sys_get_temp_dir().'/zp-pwreset-race-'.uniqid();
        mkdir($dir);

        $pids = [];
        foreach ([1, 2] as $i) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                DB::purge();
                DB::reconnect();

                $ok = app(PasswordResetService::class)->finalize($token, $proof, "Race-Winner-Pass-{$i}");
                file_put_contents($dir.'/c'.$i, $ok ? 'ok' : 'lost');
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }

        DB::purge();
        DB::reconnect();
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach ([1, 2] as $i) {
            $results[$i] = is_file($dir.'/c'.$i) ? trim((string) file_get_contents($dir.'/c'.$i)) : 'missing';
            @unlink($dir.'/c'.$i);
        }
        @rmdir($dir);

        // Exactly ONE winner; the loser returned the safe invalid-flow result.
        $this->assertCount(1, array_keys($results, 'ok'), 'winners: '.implode(',', $results));
        $this->assertCount(1, array_keys($results, 'lost'), 'losers: '.implode(',', $results));

        // Exactly one password change: the final hash matches the WINNER's
        // password, the old one is dead, and the authorization is consumed.
        $winner = (int) array_search('ok', $results, true);
        $fresh = User::findOrFail($user->id);
        $this->assertTrue(Hash::check("Race-Winner-Pass-{$winner}", $fresh->password));
        $this->assertFalse(Hash::check('Old-Race-Pass-1', $fresh->password));

        $challenge = PasswordResetChallenge::where('token', $token)->firstOrFail();
        $this->assertNotNull($challenge->consumed_at);
        // No resurrected/active challenge survives for this user.
        $this->assertSame(0, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }
}

/** The real service plus its deterministic race barrier (child-side hook). */
class PgBarrierResetService extends PasswordResetService
{
    public static ?\Closure $onBarrier = null;

    protected function raceBarrier(string $stage): void
    {
        if (self::$onBarrier !== null) {
            (self::$onBarrier)($stage);
        }
    }
}

/** The real delivery job plus its claim/transport barriers. */
class PgBarrierDeliveryJob extends SendPasswordResetOtpJob
{
    public static ?\Closure $onBeforeClaim = null;

    public static ?\Closure $onBeforeTransport = null;

    protected function beforeClaim(): void
    {
        if (self::$onBeforeClaim !== null) {
            (self::$onBeforeClaim)();
        }
    }

    protected function beforeTransport(): void
    {
        if (self::$onBeforeTransport !== null) {
            (self::$onBeforeTransport)();
        }
    }
}
