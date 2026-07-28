<?php

namespace Tests\Feature;

use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REAL concurrent finalization of one authorized password-reset challenge on
 * PostgreSQL: the row locks + fingerprint revalidation are the arbiter —
 * two parallel submissions must end with exactly ONE password change, one
 * consumed authorization, and no double remember-token/credential rotation.
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
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->userId !== null) {
            PasswordResetChallenge::where('user_id', $this->userId)->delete();
            User::whereKey($this->userId)->delete();
        }

        parent::tearDown();
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

        // Two parallel request() calls through the REAL service (user-row
        // lock serializes; supersede + create run atomically per caller).
        $results = $this->race(2, function () use ($user): string {
            $token = app(PasswordResetService::class)->request((string) $user->email);

            return is_string($token) && $token !== '' ? 'issued' : 'failed';
        });

        $this->assertSame(['issued', 'issued'], array_values($results));
        // Exactly ONE active challenge survives — the database-level partial
        // unique index (password_reset_one_active) guarantees it can never
        // be more.
        $this->assertSame(1, PasswordResetChallenge::where('user_id', $user->id)->whereNull('consumed_at')->count());
        $this->assertSame(2, PasswordResetChallenge::where('user_id', $user->id)->count());
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
