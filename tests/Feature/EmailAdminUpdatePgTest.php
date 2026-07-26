<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Atomic admin email update on REAL PostgreSQL: an edit that changes the
 * email AND another field hits a constraint AFTER the email mutation logic
 * already ran — the complete committed database state must remain identical
 * to before the edit (a savepoint under SQLite can mask partial-commit bugs
 * that real PostgreSQL transactions expose). PostgreSQL-only; manages its own
 * committed rows.
 */
class EmailAdminUpdatePgTest extends TestCase
{
    private const PREFIX = 'emailadm_';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real-transaction atomicity requires PostgreSQL (CI pgsql job).');
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

    public function test_failed_admin_edit_leaves_the_committed_state_identical(): void
    {
        User::create([
            'name' => 'x', 'username' => self::PREFIX.'holder',
            'email' => self::PREFIX.'holder@test.com', 'password' => bcrypt('x'),
        ]);
        $user = User::create([
            'name' => 'Original', 'username' => self::PREFIX.'target',
            'email' => self::PREFIX.'target@test.com', 'password' => bcrypt('x'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $code = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        $before = DB::table('users')->where('id', $user->id)->first();

        // Valid email change + a USERNAME collision that fires after the
        // email mutation logic (invalidation + forceFill) has already run.
        try {
            app(EmailVerificationService::class)->applyAdminUpdate($user, [
                'email' => self::PREFIX.'moved@test.com',
                'email_verification_action' => 'require_verification',
                'username' => self::PREFIX.'holder',
                'name' => 'Changed',
                'is_admin' => true,
            ]);
            $this->fail('the username collision must surface');
        } catch (QueryException) {
            // expected — unrelated violation rethrown untouched
        }

        // COMMITTED state is byte-for-byte the pre-edit state.
        $after = DB::table('users')->where('id', $user->id)->first();
        $this->assertEquals($before, $after, 'no partial admin edit may ever commit');
        $freshCode = $code->fresh();
        $this->assertNull($freshCode->used_at, 'OTP records remain active');
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $freshCode->send_status);
    }

    public function test_successful_admin_edit_commits_everything_together(): void
    {
        $user = User::create([
            'name' => 'Original', 'username' => self::PREFIX.'good',
            'email' => self::PREFIX.'good@test.com', 'password' => bcrypt('x'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $updated = app(EmailVerificationService::class)->applyAdminUpdate($user, [
            'email' => self::PREFIX.'good-moved@test.com',
            'email_verification_action' => 'mark_verified',
            'name' => 'Renamed',
            'is_admin' => true,
        ]);

        $this->assertSame(self::PREFIX.'good-moved@test.com', $updated->email);
        $this->assertNotNull($updated->email_verified_at);
        $this->assertSame('Renamed', $updated->name);
        $this->assertTrue((bool) $updated->is_admin);
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
