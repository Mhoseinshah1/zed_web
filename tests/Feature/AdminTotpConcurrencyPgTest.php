<?php

namespace Tests\Feature;

use App\Models\AdminTwoFactorCredential;
use App\Models\User;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * REAL concurrent TOTP consumption on PostgreSQL: parallel submissions of the
 * same code (and the same recovery code) must have exactly one winner — the
 * row lock + strictly-newer-step update is the arbiter, not test scheduling.
 *
 * No RefreshDatabase: forked children open FRESH connections, so the fixture
 * rows must be COMMITTED (a wrapping test transaction would hide them).
 * Cleanup is explicit in tearDown.
 */
class AdminTotpConcurrencyPgTest extends TestCase
{
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real same-code races require PostgreSQL (CI pgsql job).');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run real parallel submissions.');
        }
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->userIds !== []) {
            AdminTwoFactorCredential::query()->whereIn('user_id', $this->userIds)->delete();
            User::query()->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    private function committedAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->userIds[] = $admin->id;

        return $admin;
    }

    /**
     * Run $child in N forked processes and collect 'win' | 'lose' | 'crash'
     * per child. Children report through per-child result files and terminate
     * with SIGKILL: a plain exit() would run PHPUnit's shutdown handlers
     * inside the fork, which corrupts the parent test run.
     *
     * @return list<string>
     */
    private function forkAndCollect(callable $child, int $children): array
    {
        $dir = sys_get_temp_dir().'/zp-totp-race-'.uniqid();
        mkdir($dir);

        $pids = [];
        foreach (range(1, $children) as $i) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');

            if ($pid === 0) {
                // Child: fresh connection — never reuse the parent's socket.
                DB::purge();
                DB::reconnect();

                try {
                    $result = $child($i) ? 'win' : 'lose';
                } catch (\Throwable) {
                    $result = 'crash';
                }
                file_put_contents($dir.'/child-'.$i, $result);
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
            $results[] = is_file($dir.'/child-'.$i) ? trim((string) file_get_contents($dir.'/child-'.$i)) : 'missing';
            @unlink($dir.'/child-'.$i);
        }
        @rmdir($dir);

        return $results;
    }

    public function test_parallel_submissions_of_the_same_totp_code_have_exactly_one_winner(): void
    {
        $admin = $this->committedAdmin();
        $cred = $this->provisionConfirmedAdminTotp($admin);
        $code = app(Google2FA::class)->getCurrentOtp($cred->secret);

        $results = $this->forkAndCollect(
            fn () => app(AdminTotpService::class)->verifyAndConsume(User::find($admin->id), $code) !== null,
            4
        );

        $this->assertSame(
            1,
            count(array_filter($results, fn (string $r) => $r === 'win')),
            'exactly ONE of the parallel identical submissions may win ('.implode(',', $results).')'
        );
        $this->assertSame(
            3,
            count(array_filter($results, fn (string $r) => $r === 'lose')),
            'every other submission must fail cleanly, not crash ('.implode(',', $results).')'
        );
    }

    public function test_parallel_submissions_of_the_same_recovery_code_consume_it_exactly_once(): void
    {
        $admin = $this->committedAdmin();
        $totp = app(AdminTotpService::class);
        $enrollment = $totp->startEnrollment($admin);
        $codes = $totp->confirmEnrollment($admin, app(Google2FA::class)->getCurrentOtp($enrollment['secret']))['codes'];
        $target = $codes[0];

        $results = $this->forkAndCollect(
            fn () => app(AdminTotpService::class)->consumeRecoveryCode(User::find($admin->id), $target),
            4
        );

        $this->assertSame(1, count(array_filter($results, fn (string $r) => $r === 'win')),
            'a recovery code is spent exactly once ('.implode(',', $results).')');
        $this->assertSame(
            AdminTotpService::RECOVERY_CODE_COUNT - 1,
            $totp->recoveryCodesRemaining($admin)
        );
    }
}
