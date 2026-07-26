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

    public function test_a_blocked_publication_stamp_keeps_its_captured_handoff_time_and_cannot_clear_a_later_outage(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = User::create([
            'name' => 'P', 'username' => self::PREFIX.'stamp',
            'email' => self::PREFIX.'stamp@test.com', 'password' => bcrypt('x'),
        ]);

        // Publication A succeeded and its handoff moment was CAPTURED…
        $published = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        $handoffAt = now()->subSeconds(10);

        // …but its metadata UPDATE blocks behind a held row lock, and while
        // it waits, publication B FAILS and finalizes dispatch_failed.
        $blocker = DB::connection('pgsql_blocker');
        $blocker->beginTransaction();
        $blocker->select('select id from email_verification_codes where id = ? for update', [$published->id]);

        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            'send_error' => 'dispatch failed: queue down',
            'delivery_finalized_at' => now(),
            'delivery_config_fingerprint' => $svc->mailConfigFingerprint(),
        ]);

        // The blocked stamp waits only the bounded SET LOCAL lock_timeout,
        // then fails — queue_published_at stays NULL (fail-open metadata).
        $record = new \ReflectionMethod($svc, 'recordQueuePublication');
        $started = microtime(true);
        try {
            $record->invoke($svc, $published->id, $handoffAt);
            $this->fail('the blocked metadata write must time out bounded');
        } catch (QueryException) {
            // bounded 55P03 — exactly what requestCode() contains fail-open.
        }
        $this->assertLessThan(10, microtime(true) - $started, 'the metadata wait is bounded');
        $this->assertNull($published->fresh()->queue_published_at);

        // The lock clears and the stamp RESUMES with the CAPTURED handoff
        // time — older than the failure, so it can never masquerade as
        // post-outage recovery.
        $blocker->rollBack();
        $record->invoke($svc, $published->id, $handoffAt);
        $this->assertSame(
            $handoffAt->format('Y-m-d H:i:s'),
            $published->fresh()->queue_published_at?->format('Y-m-d H:i:s'),
            'the stamp records the true handoff moment, not the lock-acquisition moment',
        );
        $this->assertFalse(
            $svc->transportLooksLive(),
            'a pre-outage publication finally stamped cannot clear the later failure',
        );

        // Only a publication that actually postdates the failure recovers.
        $fresh = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('999999'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        $record->invoke($svc, $fresh->id, now());
        $this->assertTrue($svc->transportLooksLive(), 'a genuinely later publication proves recovery');
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
