<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetOtpJob;
use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * PRODUCTION cache-lock behavior for the password-reset coordination
 * boundary, against the REAL Redis service — not the file store the
 * PostgreSQL interleaving tests use.
 *
 * Redis is what production runs (`CACHE_STORE=redis`), and only Redis can
 * demonstrate its own acquisition, lease, owner-safe release and contention
 * semantics. The class forces `cache.default = redis` and skips only where
 * the evidence cannot exist at all: no PostgreSQL, no pcntl, or no reachable
 * Redis. The dedicated CI step `Password reset Redis lock coordination`
 * supplies all three and runs with `--fail-on-skipped`, so a skip there is a
 * hard failure rather than silent green.
 *
 * No RefreshDatabase: forked children open FRESH connections, so fixtures
 * must be COMMITTED and cleanup is explicit.
 */
class PasswordResetRedisLockTest extends TestCase
{
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed fixtures + forked children rule out the suite's in-memory
        // SQLite (each connection would see its own empty database), so this
        // class belongs to the PostgreSQL job — which is also the job that
        // provides the Redis service.
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real cross-process lock coordination requires PostgreSQL (CI pgsql job).');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run real parallel workers.');
        }

        config(['cache.default' => 'redis']);

        try {
            Redis::connection(config('cache.stores.redis.connection', 'default'))->ping();
            Cache::store('redis')->put('zp-redis-probe', 1, 5);
        } catch (\Throwable) {
            $this->markTestSkipped('Real Redis is required (CI pgsql job provides it; run with --fail-on-skipped).');
        }

        $this->assertSame('redis', config('cache.default'), 'these tests must exercise the REDIS store');
    }

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            try {
                Cache::store('redis')->forget(PasswordResetService::userLockKey($this->userId));
                PasswordResetChallenge::where('user_id', $this->userId)->delete();
                User::whereKey($this->userId)->delete();
            } catch (\Throwable) {
                // best effort
            }
        }

        parent::tearDown();
    }

    private function makeUser(): User
    {
        $user = User::factory()->create([
            'email' => 'redis-lock-'.uniqid().'@example.com',
            'password' => bcrypt('Old-Redis-Pass-1'),
            'email_verified_at' => now(),
        ]);
        $this->userId = $user->id;

        return $user->refresh();
    }

    /**
     * Issue a challenge WITHOUT delivering it: the race drives the delivery
     * job explicitly, so the inline (sync-queue) worker must not have already
     * transported — and finalized — the challenge before the barriers run.
     */
    private function issueChallengeFor(User $user): PasswordResetChallenge
    {
        Queue::fake();
        $outcome = app(PasswordResetService::class)->request((string) $user->email);
        $this->assertSame(PasswordResetService::OUTCOME_ISSUED, $outcome['outcome']);

        return PasswordResetChallenge::where('token', $outcome['token'])->firstOrFail();
    }

    /**
     * Drop every connection inherited across a fork.
     *
     * A forked child inherits the parent's OPEN PostgreSQL and Redis sockets.
     * Two processes multiplexing one Redis socket read each other's replies,
     * so a child can believe it acquired a lock another process holds — the
     * coordination under test would be silently faked. Each process must open
     * its OWN connections.
     */
    private function resetInheritedConnections(): void
    {
        DB::purge();
        DB::reconnect();

        $manager = app('redis');
        foreach (array_keys((array) config('database.redis')) as $name) {
            if ($name === 'client' || $name === 'options') {
                continue;
            }
            try {
                $manager->purge($name);
            } catch (\Throwable) {
                // an unresolved connection needs no purge
            }
        }
        Cache::purge('redis');
    }

    /** Fork children and collect their reports (SIGKILL: no PHPUnit shutdown). */
    private function race(int $children, callable $work): array
    {
        $dir = sys_get_temp_dir().'/zp-redis-race-'.uniqid();
        mkdir($dir);

        $pids = [];
        foreach (range(1, $children) as $i) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                $this->resetInheritedConnections();
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

        $this->resetInheritedConnections();
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

    // ── Redis lock semantics ────────────────────────────────────────────────

    public function test_redis_lock_release_is_owner_safe(): void
    {
        $user = $this->makeUser();
        $key = PasswordResetService::userLockKey($user->id);

        $owner = Cache::store('redis')->lock($key, 30);
        $this->assertTrue($owner->get(), 'owner acquires the Redis lock');

        // A DIFFERENT holder object (different owner token) must neither
        // acquire nor be able to release the live lock.
        $stranger = Cache::store('redis')->lock($key, 30);
        $this->assertFalse($stranger->get(), 'a second holder cannot acquire');
        $stranger->release();
        $this->assertFalse(
            Cache::store('redis')->lock($key, 30)->get(),
            'a stranger must never free the owner\'s lock',
        );

        $owner->release();
        $reacquired = Cache::store('redis')->lock($key, 30);
        $this->assertTrue($reacquired->get(), 'the owner\'s own release frees it');
        $reacquired->release();
    }

    public function test_redis_lock_lease_expires_so_issuance_is_never_blocked_forever(): void
    {
        $user = $this->makeUser();
        $key = PasswordResetService::userLockKey($user->id);

        // A crashed holder leaves the key behind — with a BOUNDED lease.
        $abandoned = Cache::store('redis')->lock($key, 1);
        $this->assertTrue($abandoned->get());
        $this->assertFalse(Cache::store('redis')->lock($key, 5)->get(), 'held while the lease lives');

        // Wait out the lease (bounded by the TTL itself, not an arbitrary sleep).
        $deadline = microtime(true) + 5;
        $free = false;
        while (microtime(true) < $deadline) {
            $probe = Cache::store('redis')->lock($key, 5);
            if ($probe->get()) {
                $free = true;
                $probe->release();
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($free, 'the Redis lease must expire and free the key');

        // And issuance works again afterwards.
        Mail::fake();
        $outcome = app(PasswordResetService::class)->request((string) $user->email);
        $this->assertSame(PasswordResetService::OUTCOME_ISSUED, $outcome['outcome']);
    }

    public function test_public_resend_under_redis_contention_preserves_the_valid_session(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        // Start a real flow over HTTP and capture its session challenge.
        $this->post(route('password.request.send'), ['identifier' => $user->email])->assertStatus(302);
        $token = (string) session(PasswordResetService::SESSION_TOKEN_KEY);
        $this->assertSame(1, PasswordResetChallenge::where('token', $token)->count());

        $code = null;
        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        // A delivery attempt holds the REAL Redis lock for a LONG lease …
        $leaseSeconds = 30;
        $held = Cache::store('redis')->lock(PasswordResetService::userLockKey($user->id), $leaseSeconds);
        $this->assertTrue($held->get());

        try {
            $started = microtime(true);
            $resend = $this->post(route('password.request.send'), ['identifier' => $user->email]);
            $elapsed = microtime(true) - $started;
        } finally {
            $held->release();
        }

        // … the public resend returns generically through the NON-BLOCKING
        // path, the session keeps its valid challenge, and the OTP already in
        // flight still verifies.
        $resend->assertStatus(302);
        // SECONDARY evidence only, and deliberately a GENEROUS bound: it shows
        // the request did not wait out the holder's lease. The authoritative
        // proof that issuance never blocks — and that every outcome leaves
        // through ONE timing boundary — is the injected-Timebox-spy test
        // PasswordResetTest::test_public_issuance_never_blocks_and_uses_one_timing_boundary,
        // because wall-clock thresholds are not a timing contract.
        $this->assertLessThan($leaseSeconds, $elapsed, 'the public path must not wait out a held lease');
        $this->assertSame($token, session(PasswordResetService::SESSION_TOKEN_KEY));
        $this->assertSame(1, PasswordResetChallenge::whereNull('consumed_at')->where('user_id', $user->id)->count());

        $this->post(route('password.verify.submit'), ['code' => $code])
            ->assertRedirect(route('password.reset'));
    }

    // ── Cross-process coordination on real Redis ────────────────────────────

    public function test_a_worker_holding_the_redis_lock_excludes_concurrent_issuance(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $challenge = $this->issueChallengeFor($user);
        $dir = sys_get_temp_dir().'/zp-redis-barrier-'.uniqid();
        mkdir($dir);

        $results = $this->race(2, function (int $i) use ($dir, $challenge, $user): string {
            if ($i === 1) {
                // The worker WINS the Redis lock and parks mid-transport.
                RedisBarrierDeliveryJob::$onBeforeTransport = function () use ($dir): void {
                    file_put_contents($dir.'/transport_started', '1');
                    $this->await($dir, 'issuance_attempted');
                };
                (new RedisBarrierDeliveryJob($challenge->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                return (string) PasswordResetChallenge::whereKey($challenge->id)->value('send_status');
            }

            $this->await($dir, 'transport_started');
            $outcome = app(PasswordResetService::class)->request((string) $user->email);
            file_put_contents($dir.'/issuance_attempted', '1');

            return $outcome['outcome'];
        });

        exec('rm -rf '.escapeshellarg($dir));

        // The in-flight attempt completed exactly once against real Redis, and
        // the concurrent issuance could not become authoritative mid-flight.
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $results[1], 'children: '.implode(',', $results));
        $this->assertNotSame(PasswordResetService::OUTCOME_ISSUED, $results[2], 'children: '.implode(',', $results));
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->count(), 'no duplicate challenge');
        $this->assertNull(PasswordResetChallenge::findOrFail($challenge->id)->consumed_at);
    }

    public function test_issuance_winning_the_redis_lock_prevents_a_stale_transport(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $old = $this->issueChallengeFor($user);
        $dir = sys_get_temp_dir().'/zp-redis-barrier-'.uniqid();
        mkdir($dir);

        $results = $this->race(2, function (int $i) use ($dir, $old, $user): string {
            if ($i === 1) {
                // The old worker is parked BEFORE it can claim.
                RedisBarrierDeliveryJob::$onBeforeClaim = function () use ($dir): void {
                    file_put_contents($dir.'/worker_ready', '1');
                    $this->await($dir, 'replacement_issued');
                };
                (new RedisBarrierDeliveryJob($old->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                return (string) PasswordResetChallenge::whereKey($old->id)->value('send_status');
            }

            $this->await($dir, 'worker_ready');
            $outcome = app(PasswordResetService::class)->request((string) $user->email);
            file_put_contents($dir.'/replacement_issued', '1');

            return $outcome['outcome'];
        });

        exec('rm -rf '.escapeshellarg($dir));

        // Issuance won: the stale worker transported NOTHING.
        $this->assertSame(PasswordResetService::OUTCOME_ISSUED, $results[2], 'children: '.implode(',', $results));
        $this->assertNotSame(PasswordResetChallenge::SEND_STATUS_SENT, $results[1], 'children: '.implode(',', $results));
        $stale = PasswordResetChallenge::findOrFail($old->id);
        $this->assertNotNull($stale->consumed_at);
        $this->assertNull($stale->delivery_claim_token);
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    public function test_concurrent_workers_transport_one_challenge_at_most_once(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $challenge = $this->issueChallengeFor($user);
        $dir = sys_get_temp_dir().'/zp-redis-transport-'.uniqid();
        mkdir($dir);

        // THREE workers race for the same challenge with no barrier at all —
        // real Redis contention decides the winner. Each records a marker the
        // instant it is about to call the transport, so the markers count
        // ACTUAL delivery attempts across processes (a per-process Mail::fake
        // cannot see another process's send).
        $results = $this->race(3, function (int $i) use ($dir, $challenge, $user): string {
            RedisBarrierDeliveryJob::$onBeforeTransport = function () use ($dir, $i): void {
                file_put_contents($dir.'/transport-'.$i, '1');
            };
            (new RedisBarrierDeliveryJob($challenge->id, 'email', (string) $user->email, '654321', $user->id))
                ->handle(app(SmsService::class));

            return 'done';
        });

        $attempts = glob($dir.'/transport-*') ?: [];
        exec('rm -rf '.escapeshellarg($dir));

        $this->assertSame(['done', 'done', 'done'], array_values($results));
        $this->assertCount(1, $attempts, 'one challenge must produce AT MOST one transport call');
        $this->assertSame(
            PasswordResetChallenge::SEND_STATUS_SENT,
            PasswordResetChallenge::whereKey($challenge->id)->value('send_status'),
        );
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    // ── Redis lock LOSS while a delivery claim is in flight ─────────────────

    public function test_losing_the_redis_lock_mid_transport_never_lets_issuance_supersede_a_fresh_claim(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $challenge = $this->issueChallengeFor($user);
        $key = PasswordResetService::userLockKey($user->id);
        $dir = sys_get_temp_dir().'/zp-redis-lockloss-'.uniqid();
        mkdir($dir);

        $results = $this->race(2, function (int $i) use ($dir, $key, $challenge, $user): string {
            if ($i === 1) {
                // The worker holds the REAL Redis lock, has COMMITTED its
                // claim, and parks inside the transport seam.
                RedisBarrierDeliveryJob::$onBeforeTransport = function () use ($dir): void {
                    file_put_contents($dir.'/claimed', '1');
                    $this->await($dir, 'issuance_attempted');
                };
                (new RedisBarrierDeliveryJob($challenge->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                return (string) PasswordResetChallenge::whereKey($challenge->id)->value('send_status');
            }

            $this->await($dir, 'claimed');

            // SIMULATED REDIS RESTART / EVICTION / LOST LEASE: the
            // coordination key simply disappears while the worker keeps
            // transporting, so issuance now acquires "the" lock unopposed.
            Cache::store('redis')->lock($key, 30)->forceRelease();
            $free = Cache::store('redis')->lock($key, 1)->get() ? 'free' : 'held';
            Cache::store('redis')->lock($key, 1)->forceRelease();

            $outcome = app(PasswordResetService::class)->request((string) $user->email);
            file_put_contents($dir.'/issuance_attempted', '1');

            return $free.':'.$outcome['outcome'];
        });

        exec('rm -rf '.escapeshellarg($dir));

        // The lock really was gone — and issuance STILL refused, because the
        // durable database claim, not lock ownership, is the authority.
        $this->assertStringStartsWith('free:', $results[2], 'children: '.implode(',', $results));
        $this->assertSame('free:'.PasswordResetService::OUTCOME_DECOY, $results[2], 'children: '.implode(',', $results));
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->count(), 'no replacement created');

        // The worker resumed, transported at most once, and its token-matched
        // finalization produced the authoritative terminal result.
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENT, $results[1], 'children: '.implode(',', $results));
        $survivor = PasswordResetChallenge::findOrFail($challenge->id);
        $this->assertNull($survivor->consumed_at);
        $this->assertNull($survivor->delivery_claim_token);
    }

    public function test_lock_loss_plus_a_failed_finalization_still_protects_the_in_flight_code(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $challenge = $this->issueChallengeFor($user);
        $key = PasswordResetService::userLockKey($user->id);
        $dir = sys_get_temp_dir().'/zp-redis-finalfail-'.uniqid();
        mkdir($dir);

        $results = $this->race(2, function (int $i) use ($dir, $key, $challenge, $user): string {
            if ($i === 1) {
                // Transport SUCCEEDS, then the `sent` update fails before it
                // can commit — the real boundary, not a rewritten row.
                RedisBarrierDeliveryJob::$onBeforeTransport = function () use ($dir): void {
                    file_put_contents($dir.'/transported', '1');
                };
                RedisBarrierDeliveryJob::$onBeforeFinalize = function (string $status): void {
                    if ($status === PasswordResetChallenge::SEND_STATUS_SENT) {
                        throw new \RuntimeException('bookkeeping connection lost');
                    }
                };
                (new RedisBarrierDeliveryJob($challenge->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                // The lock also disappears (restart / eviction), and the job
                // is retried: it must transport NOTHING.
                Cache::store('redis')->lock($key, 30)->forceRelease();
                RedisBarrierDeliveryJob::$onBeforeTransport = function () use ($dir): void {
                    file_put_contents($dir.'/transported-again', '1');
                };
                RedisBarrierDeliveryJob::$onBeforeFinalize = null;
                (new RedisBarrierDeliveryJob($challenge->id, 'email', (string) $user->email, '654321', $user->id))
                    ->handle(app(SmsService::class));

                file_put_contents($dir.'/worker_done', '1');

                return (string) PasswordResetChallenge::whereKey($challenge->id)->value('send_status');
            }

            $this->await($dir, 'worker_done');

            return (string) app(PasswordResetService::class)->request((string) $user->email)['outcome'];
        });

        $transports = count(glob($dir.'/transported*') ?: []);
        exec('rm -rf '.escapeshellarg($dir));

        // One transport only, the claim survives owned and fresh, and
        // issuance is refused while it is still within the window.
        $this->assertSame(1, $transports, 'exactly one transport call');
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_SENDING, $results[1], 'children: '.implode(',', $results));
        $this->assertSame(PasswordResetService::OUTCOME_DECOY, $results[2], 'children: '.implode(',', $results));
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->count());

        $stuck = PasswordResetChallenge::findOrFail($challenge->id);
        $this->assertNotNull($stuck->delivery_claim_token, 'the owner token survives a lost finalization');

        // Past the abandonment threshold the claim is retired HONESTLY and a
        // fresh challenge with a new code finally becomes available.
        PasswordResetChallenge::whereKey($challenge->id)->update([
            'delivery_claimed_at' => now()->subMinutes(PasswordResetService::ABANDONED_CLAIM_MINUTES + 1),
        ]);
        $outcome = app(PasswordResetService::class)->request((string) $user->email);

        $this->assertSame(PasswordResetService::OUTCOME_ISSUED, $outcome['outcome']);
        $retired = PasswordResetChallenge::findOrFail($challenge->id);
        $this->assertSame(PasswordResetChallenge::SEND_STATUS_DELIVERY_UNKNOWN, $retired->send_status);
        $this->assertNull($retired->delivery_claim_token);
        $this->assertNotNull($retired->consumed_at);
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    public function test_no_lock_key_or_owner_value_reaches_the_logs(): void
    {
        $user = $this->makeUser();
        $key = PasswordResetService::userLockKey($user->id);

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $held = Cache::store('redis')->lock($key, 30);
        $this->assertTrue($held->get());

        try {
            Mail::fake();
            // A contended issuance (which logs its safe reason code).
            app(PasswordResetService::class)->request((string) $user->email);
        } finally {
            $held->release();
        }

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($key, $line, 'lock key must never be logged');
            $this->assertStringNotContainsString((string) $user->email, $line);
            $this->assertStringNotContainsString('redis', strtolower($line));
        }
    }
}

/** The real delivery job plus deterministic claim/transport barriers. */
class RedisBarrierDeliveryJob extends SendPasswordResetOtpJob
{
    public static ?\Closure $onBeforeClaim = null;

    public static ?\Closure $onBeforeTransport = null;

    public static ?\Closure $onBeforeFinalize = null;

    protected function beforeFinalize(string $status): void
    {
        if (self::$onBeforeFinalize !== null) {
            (self::$onBeforeFinalize)($status);
        }
    }

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
