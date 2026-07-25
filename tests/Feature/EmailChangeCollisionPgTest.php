<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TRUE multi-process address-change collisions on real PostgreSQL: two
 * DIFFERENT users concurrently change to the same normalized email. Their
 * per-user locks use different keys, so only the DB unique index can decide —
 * exactly one wins, the loser gets a clean validation result (never a 500),
 * and the loser's email/timestamp/OTP records stay untouched. Skipped unless
 * running on PostgreSQL with pcntl (the CI pgsql job); manages its own
 * committed rows (no RefreshDatabase — forked children need to see them).
 */
class EmailChangeCollisionPgTest extends TestCase
{
    private const PREFIX = 'emailcoll_';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real multi-connection concurrency requires PostgreSQL (CI pgsql job).');
        }
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the fork-based concurrency test.');
        }

        $this->cleanup();
        SiteSetting::set('email_verification_enabled', 'true');
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->cleanup();
            SiteSetting::set('email_verification_enabled', 'false');
        }
        parent::tearDown();
    }

    /**
     * Race two users changing to (case variants of) one target address.
     *
     * @param  array<int,string>  $targets  per-child target email
     * @return array<int,int> exit codes: 0=won, 2=email validation, 1=busy/other
     */
    private function race(User $a, User $b, array $targets): array
    {
        $users = [$a->id, $b->id];
        $pids = [];
        foreach ($users as $i => $userId) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                try {
                    app('cache')->forgetDriver((string) config('cache.default'));
                    app('redis')->purge();
                } catch (\Throwable) {
                }
                try {
                    $won = app(EmailVerificationService::class)
                        ->changeAddressTo(User::find($userId), $targets[$i]);
                    exit($won ? 0 : 1);
                } catch (ValidationException) {
                    exit(2); // the clean, user-facing validation result
                } catch (\Throwable) {
                    exit(9); // anything else would surface as a 500
                }
            }
            $pids[] = $pid;
        }

        $codes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }
        DB::reconnect();

        return $codes;
    }

    public function test_two_users_racing_to_one_address_yield_one_winner_and_one_validation_error(): void
    {
        $a = User::create([
            'name' => 'A', 'username' => self::PREFIX.'a',
            'email' => self::PREFIX.'a@test.com', 'password' => bcrypt('x'),
        ]);
        $b = User::create([
            'name' => 'B', 'username' => self::PREFIX.'b',
            'email' => self::PREFIX.'b@test.com', 'password' => bcrypt('x'),
        ]);
        // email_verified_at is deliberately not mass-assignable.
        $b->forceFill(['email_verified_at' => now()])->save();
        $bCode = EmailVerificationCode::create([
            'user_id' => $b->id, 'email' => $b->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        // CASE VARIANTS of the same destination must behave identically.
        $target = self::PREFIX.'target@test.com';
        $codes = $this->race($a, $b, [$target, strtoupper(self::PREFIX).'TARGET@Test.com']);

        sort($codes);
        $this->assertSame([0, 2], $codes, 'exactly one winner; the loser gets the email validation result (never a crash/500)');

        $a->refresh();
        $b->refresh();
        $holders = collect([$a, $b])->filter(fn (User $u) => strcasecmp((string) $u->email, $target) === 0);
        $this->assertCount(1, $holders, 'exactly one user holds the target address');

        // The LOSER kept everything: original email, verification timestamp,
        // and OTP records — the losing transaction left no partial state.
        $loser = strcasecmp((string) $a->email, $target) === 0 ? $b : $a;
        $this->assertStringContainsString(self::PREFIX, (string) $loser->email);
        if ($loser->id === $b->id) {
            $this->assertNotNull($loser->email_verified_at, 'loser keeps their verification timestamp');
            $this->assertNull($bCode->fresh()->used_at, 'loser keeps their OTP records untouched');
        }

        // The WINNER restarts verification from zero.
        $winner = $holders->first();
        $this->assertNull($winner->email_verified_at);
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
