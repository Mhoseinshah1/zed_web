<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use App\Support\EmailUniqueViolationProbe;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TRUE multi-process email-verification concurrency against a real PostgreSQL
 * server (and, in CI, real Redis-backed Cache::lock): forked workers on their
 * own connections race issuance, verification, address changes and
 * registration inserts. The per-user lock + row locks must keep every
 * invariant. Skipped unless running on PostgreSQL with pcntl (the CI pgsql
 * job). Deliberately does NOT use RefreshDatabase: forked children need
 * COMMITTED setup data on their own connections, so this test manages and
 * cleans up its own rows.
 */
class EmailConcurrencyPgTest extends TestCase
{
    private const PREFIX = 'emailfork_';

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

    /** Fork workers; each runs $work($i) on a FRESH connection. Returns exit codes. */
    private function forkWorkers(int $count, callable $work): array
    {
        $pids = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                try {
                    exit((int) $work($i));
                } catch (\Throwable) {
                    exit(9);
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

    private function makeUser(string $suffix): User
    {
        return User::create([
            'name' => 'F', 'username' => self::PREFIX.$suffix,
            'email' => self::PREFIX.$suffix.'@test.com',
            'password' => bcrypt('x'), 'email_verified_at' => null,
        ]);
    }

    public function test_simultaneous_resends_create_exactly_one_active_code(): void
    {
        $user = $this->makeUser('resend');

        $codes = $this->forkWorkers(4, function () use ($user) {
            $result = app(EmailVerificationService::class)->requestCode(User::find($user->id));

            return $result['status'] === 'queued' ? 0 : 1;
        });

        $active = EmailVerificationCode::where('user_id', $user->id)->whereNull('used_at')->count();
        $this->assertSame(1, $active, 'exactly ONE active code under contention');
        $this->assertSame(1, count(array_filter($codes, fn ($c) => $c === 0)), 'exactly one worker wins the cooldown gate');
        $this->assertNotContains(9, $codes, 'no worker may crash — contention must be a controlled response');
    }

    public function test_concurrent_valid_verification_succeeds_exactly_once(): void
    {
        $user = $this->makeUser('verify');
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        $codes = $this->forkWorkers(4, function () use ($user) {
            $result = app(EmailVerificationService::class)->verify(User::find($user->id), '123456');

            return $result['status'] === 'verified' ? 0 : 1;
        });

        // The single-use claim: exactly one success; the timestamp is set.
        $this->assertSame(1, count(array_filter($codes, fn ($c) => $c === 0)), 'the code is single-use even under contention');
        $this->assertNotNull(User::find($user->id)->email_verified_at);
        $this->assertSame(0, EmailVerificationCode::where('user_id', $user->id)->whereNull('used_at')->count());
    }

    public function test_verify_racing_an_address_change_never_verifies_the_new_address(): void
    {
        $user = $this->makeUser('race1');
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        $newEmail = self::PREFIX.'race1-new@test.com';

        $this->forkWorkers(2, function (int $i) use ($user, $newEmail) {
            if ($i === 0) {
                $result = app(EmailVerificationService::class)->verify(User::find($user->id), '123456');

                return $result['status'] === 'verified' ? 0 : 1;
            }
            usleep(random_int(0, 20000));

            return app(EmailVerificationService::class)->changeAddressTo(User::find($user->id), $newEmail) ? 0 : 1;
        });

        // INVARIANT: a code issued to the old mailbox can never leave the NEW
        // unproven address marked verified — whichever order the lock chose.
        $fresh = User::find($user->id);
        if (strcasecmp((string) $fresh->email, $newEmail) === 0) {
            $this->assertNull($fresh->email_verified_at, 'new address must remain unverified');
        }
    }

    public function test_address_change_racing_issuance_leaves_only_codes_for_the_final_address(): void
    {
        $user = $this->makeUser('race2');
        $newEmail = self::PREFIX.'race2-new@test.com';

        $this->forkWorkers(2, function (int $i) use ($user, $newEmail) {
            if ($i === 0) {
                $result = app(EmailVerificationService::class)->requestCode(User::find($user->id));

                return in_array($result['status'], ['queued', 'busy', 'rate_limited'], true) ? 0 : 1;
            }
            usleep(random_int(0, 20000));

            return app(EmailVerificationService::class)->changeAddressTo(User::find($user->id), $newEmail) ? 0 : 1;
        });

        // INVARIANT: every remaining ACTIVE code targets the user's current
        // address — issue-then-change invalidates, change-then-issue issues to
        // the new address (the locked row is the single source of truth).
        $fresh = User::find($user->id);
        foreach (EmailVerificationCode::where('user_id', $user->id)->whereNull('used_at')->get() as $code) {
            $this->assertSame(0, strcasecmp($code->email, (string) $fresh->email), 'active codes may only target the CURRENT address');
        }
    }

    public function test_max_attempts_cannot_be_bypassed_concurrently(): void
    {
        SiteSetting::set('email_otp_max_attempts', 5);
        $user = $this->makeUser('attempts');
        $record = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        $this->forkWorkers(10, function () use ($user) {
            app(EmailVerificationService::class)->verify(User::find($user->id), '999999');

            return 0;
        });

        $this->assertLessThanOrEqual(5, $record->fresh()->attempts, 'the attempt counter is incremented under the lock');
        // And the correct code is refused afterwards.
        $result = app(EmailVerificationService::class)->verify(User::find($user->id), '123456');
        $this->assertNotSame('verified', $result['status']);
        SiteSetting::set('email_otp_max_attempts', (string) EmailVerificationService::MAX_ATTEMPTS);
    }

    public function test_concurrent_registration_inserts_create_exactly_one_user(): void
    {
        $email = self::PREFIX.'collide@test.com';

        $codes = $this->forkWorkers(2, function (int $i) use ($email) {
            try {
                DB::transaction(function () use ($i, $email) {
                    User::create([
                        'name' => 'F', 'username' => self::PREFIX.'collide'.$i,
                        'email' => $email, 'password' => bcrypt('x'),
                    ]);
                });

                return 0;
            } catch (QueryException $e) {
                // The register() endpoint converts exactly this class of
                // violation into an email validation error (never a 500).
                return EmailUniqueViolationProbe::isEmailUniqueViolation($e) ? 2 : 1;
            }
        });

        sort($codes);
        $this->assertSame([0, 2], $codes, 'one insert wins; the loser sees a classifiable EMAIL unique violation');
        $this->assertSame(1, User::whereRaw('lower(email) = ?', [strtolower($email)])->count(), 'exactly one user row remains');
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
