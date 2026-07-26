<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
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
