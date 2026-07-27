<?php

namespace Tests\Feature;

use App\Models\PasswordResetChallenge;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
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
            'auth_session_hash' => hash('sha256', $proof),
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
