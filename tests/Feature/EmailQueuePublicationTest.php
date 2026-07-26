<?php

namespace Tests\Feature;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Email\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * queue_published_at (CONFIRMED dispatch handoff) and transport_attempted_at
 * (a real Mail::send began) — the two explicit evidence timestamps behind
 * publication-outage classification, worker-stall detection, and the
 * zero-transport superseded-code restoration.
 */
class EmailQueuePublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SiteSetting::set('email_verification_enabled', 'true');
        // A valid transport-test proof so isEnforceableNow() reflects only
        // the pipeline-health signals under test.
        app(EmailVerificationService::class)->recordSuccessfulMailTest();
    }

    private function user(): User
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->forceFill(['email_verification_required_at_registration' => true])->save();

        return $user;
    }

    private function svc(): EmailVerificationService
    {
        return app(EmailVerificationService::class);
    }

    public function test_publication_metadata_is_stamped_only_after_successful_dispatch(): void
    {
        Queue::fake();
        $user = $this->user();

        $result = $this->svc()->requestCode($user);
        $this->assertSame('queued', $result['status']);

        $record = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $record->send_status);
        $this->assertNotNull($record->queue_published_at, 'dispatch returned successfully — publication confirmed');
        $this->assertNull($record->transport_attempted_at, 'no transport ran (job only queued)');
        Queue::assertPushed(SendEmailOtpJob::class, 1);
    }

    public function test_a_publication_failure_leaves_no_stamp_and_restores_the_superseded_code(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        // The previously DELIVERED, still-usable code.
        $delivered = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        EmailVerificationCode::whereKey($delivered->id)->update(['created_at' => now()->subMinutes(3)]);

        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue down'));
        Queue::shouldReceive('push')->andThrow(new \RuntimeException('queue down'));

        $this->assertSame('error', $svc->requestCode($user)['status']);

        $replacement = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, $replacement->send_status);
        $this->assertNull($replacement->queue_published_at, 'a failed dispatch confirms nothing');
        $this->assertNull($replacement->transport_attempted_at);
        $this->assertNotNull($replacement->used_at, 'the dead replacement is consumed');
        $this->assertNull($delivered->fresh()->used_at, 'the delivered code is restored');
        $this->assertFalse($svc->transportLooksLive(), 'an unpublished dispatch failure is publication-outage evidence');
    }

    public function test_sync_success_preserves_the_terminal_state_and_still_stamps_publication(): void
    {
        Mail::fake();
        $user = $this->user();

        $result = $this->svc()->requestCode($user);
        $this->assertSame('queued', $result['status']);

        // The sync driver ran the whole delivery INSIDE dispatch(): the row
        // is already terminal, and the after-dispatch metadata stamp must
        // record the successful publication WITHOUT touching that state.
        $record = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_SENT, $record->send_status);
        $this->assertNotNull($record->queue_published_at, 'publication is stamped even after a terminal transition');
        $this->assertNotNull($record->transport_attempted_at, 'the real transport attempt was recorded pre-send');
    }

    public function test_metadata_stamp_failure_after_successful_dispatch_is_contained(): void
    {
        Queue::fake();
        Log::spy();
        $user = $this->user();

        /** @var EmailVerificationService $svc */
        $svc = Mockery::mock(EmailVerificationService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('recordQueuePublication')->once()->andThrow(new \RuntimeException('metadata write failed'));

        $result = $svc->requestCode($user);

        // Dispatch DID succeed: the user-facing result stands, the job is
        // pushed exactly once, the row is neither failed nor re-dispatched,
        // and only the conservative NULL stamp remains (health fails open).
        $this->assertSame('queued', $result['status']);
        Queue::assertPushed(SendEmailOtpJob::class, 1);
        $record = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_QUEUED, $record->send_status, 'never dispatch_failed');
        $this->assertNull($record->queue_published_at, 'the stamp stays NULL — recovery cannot be proven by this row');
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => str_contains((string) $message, 'queue-publication metadata'),
        )->once();
    }

    public function test_a_created_but_unpublished_row_is_never_stall_or_recovery_evidence(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        // Old queued row with NO confirmed publication: it may never have
        // reached the queue — not stalled-worker evidence.
        $stale = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        EmailVerificationCode::whereKey($stale->id)->update([
            'created_at' => now()->subMinutes(EmailVerificationService::STALLED_QUEUE_MINUTES + 2),
        ]);
        $this->assertTrue($svc->transportLooksLive(), 'an unconfirmed publication cannot prove a dead worker');

        // With a real publication failure present, the same unpublished row
        // is not recovery evidence either.
        EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            'send_error' => 'dispatch failed: queue down',
            'delivery_finalized_at' => now()->subMinutes(3),
            'delivery_config_fingerprint' => $svc->mailConfigFingerprint(),
        ]);
        $this->assertFalse($svc->transportLooksLive(), 'a merely-created queued row proves nothing about the queue');

        // A CONFIRMED publication after the failure proves recovery — and a
        // published-but-unconsumed row past the threshold becomes genuine
        // stall evidence.
        EmailVerificationCode::whereKey($stale->id)->update(['queue_published_at' => now()]);
        $this->assertTrue($svc->transportLooksLive(), 'the confirmed publication clears the outage');

        EmailVerificationCode::whereKey($stale->id)->update([
            'queue_published_at' => now()->subMinutes(EmailVerificationService::STALLED_QUEUE_MINUTES + 1),
        ]);
        $this->assertFalse($svc->transportLooksLive(), 'published-and-never-consumed IS stalled-worker evidence');
    }

    public function test_published_dispatch_failures_are_not_publication_outage_evidence(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        // Publication succeeded; processing exhausted before any transport
        // (failed() closed them out). Neither queue-outage nor transport
        // evidence.
        foreach (range(1, 3) as $i) {
            $row = EmailVerificationCode::create([
                'user_id' => $user->id, 'email' => $user->email,
                'code_hash' => Hash::make('123456'), 'attempts' => 0,
                'expires_at' => now()->addMinutes(10), 'used_at' => now(),
                'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                'send_error' => 'delivery failed: lock_contention',
                'delivery_finalized_at' => now()->subMinutes($i),
                'delivery_config_fingerprint' => $svc->mailConfigFingerprint(),
            ]);
            EmailVerificationCode::whereKey($row->id)->update([
                'queue_published_at' => now()->subMinutes($i + 1),
            ]);
        }

        $this->assertTrue($svc->transportLooksLive(), 'post-publication exhaustion is not a queue outage');
        $this->assertTrue($svc->isEnforceableNow());
    }

    public function test_sync_lock_contention_with_zero_transport_restores_the_delivered_code(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        $delivered = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        EmailVerificationCode::whereKey($delivered->id)->update(['created_at' => now()->subMinutes(3)]);

        // The per-user lock is seized the moment the SYNC job starts (after
        // requestCode released its own hold): the inline job can neither
        // claim nor retry, no transport ever runs, and the failure surfaces
        // back through dispatch(). The contention clears once the job throws
        // (JobExceptionOccurred) — exactly when failed() runs its bounded
        // cleanup and the zero-transport restoration.
        $held = null;
        Event::listen(JobProcessing::class, function () use ($user, &$held) {
            $held = Cache::lock(EmailVerificationService::userLockKey($user->id), 60);
            $held->get();
        });
        Event::listen(JobExceptionOccurred::class, function () use (&$held) {
            $held?->forceRelease();
            $held = null;
        });

        try {
            $result = $svc->requestCode($user);
        } finally {
            $held?->forceRelease();
        }

        $this->assertSame('error', $result['status'], 'the replacement was honestly reported as not sent');
        $replacement = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, $replacement->send_status);
        $this->assertNull($replacement->transport_attempted_at, 'zero transport attempts');
        $this->assertNotNull($replacement->used_at, 'the dead replacement cannot be advertised');
        $this->assertNull($delivered->fresh()->used_at, 'the delivered code is usable again');
        $this->assertSame('verified', $svc->verify($user->fresh(), '123456')['status'], 'and it still verifies');
    }

    public function test_failed_restores_the_superseded_code_after_published_zero_transport_exhaustion(): void
    {
        $user = $this->user();

        $delivered = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        EmailVerificationCode::whereKey($delivered->id)->update(['created_at' => now()->subMinutes(3)]);
        $replacement = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('654321'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
        ]);
        EmailVerificationCode::whereKey($replacement->id)->update(['queue_published_at' => now()]);

        // Async: the published job exhausted every attempt on contention
        // BEFORE any claim or transport — failed() closes it out and
        // restores the delivered code carried in the encrypted payload.
        (new SendEmailOtpJob($replacement->id, $user->id, (string) $user->email, '654321', 10, $delivered->id))
            ->failed(new \RuntimeException('delivery failed: lock_contention (no retry available on the current queue driver)'));

        $fresh = $replacement->fresh();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED, $fresh->send_status);
        $this->assertNotNull($fresh->queue_published_at, 'publication evidence is PRESERVED');
        $this->assertNotNull($fresh->used_at, 'the dead replacement is consumed');
        $this->assertNull($delivered->fresh()->used_at, 'the delivered code is restored');
        $this->assertTrue($this->svc()->transportLooksLive(), 'a published exhaustion is not a publication outage');
    }

    public function test_a_real_transport_attempt_blocks_restoration(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        $delivered = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);
        EmailVerificationCode::whereKey($delivered->id)->update(['created_at' => now()->subMinutes(3)]);

        // The SYNC replacement reaches a REAL transport attempt and the SMTP
        // server rejects it: the inbox state is decided by the transport —
        // the superseded code must NOT come back.
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->andThrow(new \RuntimeException('SMTP error: connection lost'));
        Mail::shouldReceive('to')->andReturn($pending);

        $this->assertSame('error', $svc->requestCode($user)['status']);

        $replacement = EmailVerificationCode::latest('id')->first();
        $this->assertSame(EmailVerificationCode::SEND_STATUS_FAILED, $replacement->send_status, 'an honest transport failure');
        $this->assertNotNull($replacement->transport_attempted_at, 'the transport attempt was recorded');
        $this->assertNotNull($delivered->fresh()->used_at, 'the superseded code stays consumed');
    }

    public function test_restoration_is_idempotent_and_refuses_changed_email_or_verified_user(): void
    {
        $svc = $this->svc();
        $user = $this->user();

        $old = EmailVerificationCode::create([
            'user_id' => $user->id, 'email' => $user->email,
            'code_hash' => Hash::make('123456'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(8), 'used_at' => now(),
            'send_status' => EmailVerificationCode::SEND_STATUS_SENT,
        ]);

        // Changed address: the code was delivered to an address the account
        // no longer uses — refused.
        $originalEmail = $user->email;
        $user->forceFill(['email' => 'changed_'.$originalEmail])->save();
        $svc->restoreSupersededCode($old->id, $user->id);
        $this->assertNotNull($old->fresh()->used_at, 'refused for a changed address');
        $user->forceFill(['email' => $originalEmail])->save();

        // Already-verified user: nothing to restore for — refused.
        $user->forceFill(['email_verified_at' => now()])->save();
        $svc->restoreSupersededCode($old->id, $user->id);
        $this->assertNotNull($old->fresh()->used_at, 'refused for a verified user');
        $user->forceFill(['email_verified_at' => null])->save();

        // Valid: restored once; the second call is a no-op and exactly one
        // actionable code exists throughout.
        $svc->restoreSupersededCode($old->id, $user->id);
        $this->assertNull($old->fresh()->used_at, 'restored');
        $svc->restoreSupersededCode($old->id, $user->id);
        $this->assertNull($old->fresh()->used_at, 'idempotent');
        $this->assertSame(
            1,
            EmailVerificationCode::query()->actionableFor($user->fresh())->count(),
            'exactly one actionable code',
        );
    }
}
