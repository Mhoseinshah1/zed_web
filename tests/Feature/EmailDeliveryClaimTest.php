<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Exception;
use Tests\TestCase;

/**
 * Claim-owned OTP delivery finalization: every attempt owns a random
 * per-attempt claim token, finalization only ever mutates the claim the same
 * worker made, lost cache-lock ownership degrades to the honest
 * `accepted_pending` state instead of a guessed `sent`, and the ACTIONABLE
 * positive-list status scope drives every "active code" decision.
 */
class EmailDeliveryClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('email_verification_enabled', 'true');
    }

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => null]);
    }

    private function queuedRecord(User $user, string $code = '123456'): EmailVerificationCode
    {
        return EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
    }

    private function job(EmailVerificationCode $record, User $user): SendEmailOtpJob
    {
        return new SendEmailOtpJob($record->id, $user->id, (string) $user->email, '123456', 10);
    }

    // ── Claim ownership ──────────────────────────────────────────────────────

    public function test_normal_delivery_sends_once_becomes_sent_and_clears_the_claim(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);

        $this->job($record, $user)->handle();

        Mail::assertSentCount(1);
        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->send_status);
        $this->assertNull($record->delivery_claim_token, 'terminal states clear the claim');
        $this->assertNull($record->delivery_claimed_at);
        // The raw token never appears in serialized output.
        $this->assertArrayNotHasKey('delivery_claim_token', $record->toArray());
    }

    public function test_claim_skips_codes_without_a_usable_delivery_margin(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);
        // Unexpired — but with less validity left than the delivery margin: a
        // backlogged job must not email a code that can die during (or right
        // after) the SMTP exchange while the message calls it valid.
        EmailVerificationCode::whereKey($record->id)->update(['expires_at' => now()->addSeconds(45)]);

        $this->job($record, $user)->handle();

        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $record->fresh()->send_status);
    }

    public function test_sync_job_lock_contention_throws_instead_of_silently_releasing(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);

        // SyncJob executes exactly once — release() is a no-op. Under lock
        // contention the job must THROW (propagating into requestCode's
        // dispatch catch → dispatch_failed) instead of returning as if a
        // delayed retry existed while the record stays `queued` forever.
        $job = $this->job($record, $user);
        $job->setJob(new SyncJob(app(), json_encode([]), 'sync', 'sync'));

        $lock = Cache::lock(EmailVerificationService::userLockKey($user->id), 60);
        $this->assertTrue($lock->get());
        try {
            $job->handle();
            $this->fail('sync lock contention must surface as a failure');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(Exception::class, $e);
            $this->assertStringContainsString('lock_contention', $e->getMessage());
        } finally {
            $lock->release();
        }
        Mail::assertNothingSent();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $record->fresh()->send_status, 'unclaimed — failed() will close it out as dispatch_failed');
    }

    public function test_cache_outage_during_ownership_check_finalizes_as_accepted_pending(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);

        // Redis dies AFTER the transport accepted, DURING the final ownership
        // check: an unverifiable lock is LOST ownership — the record must
        // close out as accepted_pending (terminal), never roll back to
        // `sending` where a retry would duplicate the accepted message.
        $lock = Mockery::mock(Lock::class)->shouldIgnoreMissing();
        $lock->shouldReceive('block')->andReturn(true);
        $lock->shouldReceive('isOwnedByCurrentProcess')->andThrow(new \RuntimeException('redis connection refused'));
        $lock->shouldReceive('release')->andThrow(new \RuntimeException('redis connection refused'));
        Cache::shouldReceive('lock')->andReturn($lock);

        $this->job($record, $user)->handle();

        $fresh = $record->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING, $fresh->send_status);
        $this->assertNull($fresh->delivery_claim_token, 'terminal — never re-claimable into a re-send');
    }

    public function test_claim_revalidates_the_delivery_policy_before_sending(): void
    {
        Mail::fake();
        $user = $this->user();

        // Admin disables verification while the job sits in the backlog: the
        // stale job must skip, not deliver.
        $disabled = $this->queuedRecord($user);
        SiteSetting::set('email_verification_enabled', 'false');
        $this->job($disabled, $user)->handle();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $disabled->fresh()->send_status);

        // Mailer degrades to a non-deliverable graph: sending would only
        // pretend (and with log/array could leak the plaintext OTP) — skip.
        SiteSetting::set('email_verification_enabled', 'true');
        $undeliverable = $this->queuedRecord($user);
        config(['mail.default' => 'not-a-real-mailer']);
        $this->job($undeliverable, $user)->handle();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SKIPPED, $undeliverable->fresh()->send_status);

        Mail::assertNothingSent();
    }

    public function test_claim_token_changes_on_retry(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);
        // A previous attempt left the record claimed with ITS token.
        $oldToken = bin2hex(random_bytes(32));
        EmailVerificationCode::whereKey($record->id)->update([
            'send_status' => EmailVerificationCode::SEND_STATUS_SENDING,
            'delivery_claim_token' => $oldToken,
            'delivery_claimed_at' => now()->subMinute(),
        ]);

        // The RETRY (attempts > 1) re-claims under a FRESH token. Capture the
        // in-flight token via the Mail hook, mid-send.
        $seenToken = null;
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use ($record, &$seenToken) {
            $seenToken = $record->fresh()->delivery_claim_token;
        });
        Mail::clearResolvedInstances();
        Mail::shouldReceive('to')->andReturn($pending);

        $retryContext = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $retryContext->shouldReceive('attempts')->andReturn(2);
        $job = $this->job($record, $user);
        $job->setJob($retryContext);
        $job->handle();

        $this->assertNotNull($seenToken);
        $this->assertNotSame($oldToken, $seenToken, 'every attempt owns a FRESH claim token');
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
    }

    public function test_a_worker_never_touches_another_workers_claim(): void
    {
        $user = $this->user();
        $record = $this->queuedRecord($user);
        $foreignToken = bin2hex(random_bytes(32));

        // Mid-send, worker B "steals" the record: re-claims it under ITS OWN
        // token (as a retry would after A's cache lock expired).
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use ($record, $foreignToken) {
            EmailVerificationCode::whereKey($record->id)->update([
                'delivery_claim_token' => $foreignToken,
                'delivery_claimed_at' => now(),
            ]);
        });
        Mail::shouldReceive('to')->andReturn($pending);

        $this->job($record, $user)->handle();

        // Worker A finalizes NOTHING: not sent, not skipped — B's claim is
        // intact and B alone will finalize it.
        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENDING, $record->send_status);
        $this->assertSame($foreignToken, $record->delivery_claim_token);
    }

    public function test_lost_cache_lock_ownership_becomes_accepted_pending_never_sent(): void
    {
        $user = $this->user();
        $record = $this->queuedRecord($user);
        $lockKey = EmailVerificationService::userLockKey($user->id);

        // Mid-send, worker A's cache lock "expires" and ANOTHER owner takes it.
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andReturnUsing(function () use ($lockKey) {
            Cache::lock($lockKey)->forceRelease();
            $this->assertTrue(Cache::lock($lockKey, 60)->get(), 'another owner acquires the expired lock');
        });
        Mail::shouldReceive('to')->andReturn($pending);

        $this->job($record, $user)->handle();

        // Transport DID accept — but ownership is uncertain, so the honest
        // terminal marker is accepted_pending: never a guessed `sent`, never
        // claimable into a duplicate send, claim fields cleared.
        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING, $record->send_status);
        $this->assertNull($record->delivery_claim_token);
        $this->assertNotContains(
            EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
            [EmailVerificationCode::SEND_STATUS_QUEUED, EmailVerificationCode::SEND_STATUS_SENDING],
            'accepted_pending is not claimable',
        );
        Cache::lock($lockKey)->forceRelease();
    }

    public function test_transport_failure_fails_only_the_matching_claim_and_clears_it(): void
    {
        $user = $this->user();
        $record = $this->queuedRecord($user);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP 451 temporary failure'));

        try {
            $this->job($record, $user)->handle();
            $this->fail('the sanitized transport failure must surface');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(Exception::class, $e, 'a PHPUnit failure must never be mistaken for the expected exception');
            $this->assertStringStartsWith('delivery failed:', $e->getMessage());
        }

        // Direct invocation = final attempt: the record fails token-matched.
        $record->refresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $record->send_status);
        $this->assertNull($record->delivery_claim_token, 'terminal states clear the claim');
    }

    public function test_states_remain_monotonic_after_terminal(): void
    {
        Mail::fake();
        $user = $this->user();
        $record = $this->queuedRecord($user);
        $this->job($record, $user)->handle();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);

        // Re-delivery of the same job: not claimable, nothing re-sent.
        $this->job($record, $user)->handle();
        Mail::assertSentCount(1);
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);

        // failed() cannot regress a terminal state either.
        $this->job($record, $user)->failed(new \RuntimeException('late'));
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->fresh()->send_status);
    }

    // ── Actionable-code rules ────────────────────────────────────────────────

    public function test_only_actionable_statuses_hold_a_lifetime_or_the_cooldown(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->user();

        foreach ([
            EmailVerificationCode::SEND_STATUS_FAILED,
            EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            EmailVerificationCode::SEND_STATUS_SKIPPED,
        ] as $i => $status) {
            EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('00000'.$i),
                'expires_at' => now()->addMinutes(10), 'attempts' => 0,
                'send_status' => $status,
            ]);
        }
        // An EXPIRED sent code and a USED sent code are equally non-actionable.
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('000003'),
            'expires_at' => now()->subMinute(), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('000004'), 'used_at' => now(),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        $this->assertNull($svc->activeCodeRemainingMinutes($user), 'no unusable record may advertise a lifetime');
        $this->assertTrue($svc->canResend($user), 'resend stays immediately available after failed/skipped/dispatch_failed');
    }

    public function test_newest_actionable_code_wins_and_a_newer_terminal_one_does_not_hide_it(): void
    {
        $svc = app(EmailVerificationService::class);
        $user = $this->user();

        // Older, still-actionable sent code (5 minutes left)…
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('111111'),
            'expires_at' => now()->addMinutes(5)->addSeconds(30), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        // …followed by a NEWER record that terminally failed.
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('222222'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
        ]);

        $this->assertSame(5, $svc->activeCodeRemainingMinutes($user), 'the older still-actionable code is what the user can really use');
    }

    public function test_notice_page_shows_a_request_new_code_state_when_nothing_is_actionable(): void
    {
        $user = $this->user();
        $user->forceFill(['email_verification_required_at_registration' => true])->save();
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0,
            'send_status' => EmailVerificationCode::SEND_STATUS_FAILED,
        ]);

        $html = $this->actingAs($user)->get('/email/verify')->getContent();

        // Never implies the undelivered failed code is currently valid.
        $this->assertStringContainsString('کد فعالی', $html);
        $this->assertStringNotContainsString('کد ۶ رقمی ارسال‌شده', $html);
    }
}
