<?php

namespace App\Services\Email;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\DatabaseLockTimeout;
use App\Support\EmailUniqueViolationProbe;
use App\Support\MailFailure;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * OTP-based email verification (mirrors the PhoneVerificationService
 * architecture; no signed-link flow — the user types a 6-digit code).
 *
 * Codes are 6-digit numeric, hashed at rest, single-use, TTL-bound, with a
 * resend cooldown and a rolling daily cap. Delivery goes through a queued,
 * encrypted job that records an honest send_status — a failed dispatch is
 * reported as a failure, never as "code sent".
 */
class EmailVerificationService
{
    /**
     * Resolved lazily rather than promoted into the constructor: existing tests
     * build this service with `Mockery::mock(...)->makePartial()`, which never
     * runs the constructor, and a promoted readonly property would then be
     * uninitialised on first use.
     */
    private ?MailPipelineHealth $pipeline = null;

    public function __construct(?MailPipelineHealth $pipeline = null)
    {
        $this->pipeline = $pipeline;
    }

    private function pipeline(): MailPipelineHealth
    {
        return $this->pipeline ??= app(MailPipelineHealth::class);
    }

    // Defaults — overridden by admin settings at runtime.
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SEC = 60;

    public const DAILY_CAP = 10;

    /** Bounded wait for the per-user serialization lock (seconds). */
    public const LOCK_WAIT_SECONDS = 3;

    /** TTL of the per-user lock — an abandoned lock can never outlive this. */
    public const LOCK_TTL_SECONDS = 10;

    // ── Delivery-pipeline health ─────────────────────────────────────────────
    //
    // These thresholds now live on MailPipelineHealth, which owns every probe
    // that reads them. They are re-exported here because callers and tests
    // reference them through this class, and a single source of truth is worth
    // more than a tidier constant list.

    public const MAIL_TEST_PROOF_MAX_DAYS = MailPipelineHealth::MAIL_TEST_PROOF_MAX_DAYS;

    public const OUTAGE_WINDOW_MINUTES = MailPipelineHealth::OUTAGE_WINDOW_MINUTES;

    public const OUTAGE_MIN_FAILURES = MailPipelineHealth::OUTAGE_MIN_FAILURES;

    public const STALLED_QUEUE_MINUTES = MailPipelineHealth::STALLED_QUEUE_MINUTES;

    public const ABANDONED_SENDING_MINUTES = MailPipelineHealth::ABANDONED_SENDING_MINUTES;

    public const STALL_MARKER_KEY = MailPipelineHealth::STALL_MARKER_KEY;

    /** Shown when the bounded per-user lock could not be acquired in time. */
    public const BUSY_MESSAGE = 'سیستم موقتاً مشغول است. لطفاً چند لحظه بعد دوباره تلاش کنید.';

    // ── Settings ─────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('email_verification_enabled', false);
    }

    /**
     * Required-at-registration only takes effect while the mail configuration
     * is actually usable — a misconfigured "required" flag can never lock
     * users out of their accounts.
     */
    public function isRequiredOnRegister(): bool
    {
        // FAIL-SAFE at runtime, not only at save time: required mode demands a
        // usable configuration AND a still-valid successful transport test.
        // When the operational configuration drifts (fingerprint change) or
        // the proof expires, enforcement silently degrades to optional so new
        // users are never locked out behind an unproven mailer.
        //
        // The enabled/required PAIR is read in ONE statement (mirroring the
        // settings page's one-transaction save), so this can never observe a
        // half-applied policy — enabled from a new save, required from the
        // old one — and stamp a registration with a mixed state.
        $flags = SiteSetting::query()
            ->whereIn('key', ['email_verification_enabled', 'email_verification_required_on_register'])
            ->pluck('value', 'key');

        return $this->settingIsTrue($flags->get('email_verification_enabled'))
            && $this->settingIsTrue($flags->get('email_verification_required_on_register'))
            && $this->isMailConfigured()
            && $this->hasVerifiedMailTest()
            && $this->lockBackendLooksAvailable()
            // A LIVE outage (endpoint died without any config change) also
            // suspends stamping: registrations during it are permanently
            // exempt, exactly like the other fail-safe windows.
            && $this->transportLooksLive();
    }

    /** Truthiness matching SiteSetting::get's coercion ('true' or numeric). */
    private function settingIsTrue(?string $value): bool
    {
        return $value === 'true' || (is_numeric($value) && (int) $value !== 0);
    }

    /**
     * The effective policy for a registration being INSERTED right now. MUST
     * be called inside the registration transaction: the enabled/required
     * pair is read under a SHARED row lock, so a concurrent admin policy
     * save (its UPDATE blocks until this transaction ends) serializes with
     * the immutable marker write — the stamped value always reflects a fully
     * committed policy, never one that flips between this read and the user
     * insert. The mail fail-safes (configuration, proof, live health) join
     * the decision exactly as in isRequiredOnRegister().
     */
    public function captureRequiredPolicyForRegistration(): bool
    {
        // A shared lock only serializes against rows that EXIST. The seed
        // migration guarantees the pair on deployed instances; this makes
        // the guarantee unconditional (deleted rows, pre-migration DBs) —
        // insertOrIgnore is race-safe under the unique `key` index and never
        // overwrites live values.
        SiteSetting::query()->insertOrIgnore([
            ['key' => 'email_verification_enabled', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'email_verification_required_on_register', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // One row per statement, in the WRITER'S order (enabled → required —
        // the exact sequence EmailSettingsPage::save() updates them in). A
        // single whereIn statement lets PostgreSQL lock the pair in SCAN
        // order, which can oppose the writer's and deadlock (40P01 → 500 on
        // registration). Ordered acquisition removes the cycle, and the read
        // stays tear-free: holding the shared lock on `enabled` blocks the
        // save at its FIRST update, so `required` cannot have been rewritten
        // by a save this read only partially observed.
        $flags = collect(['email_verification_enabled', 'email_verification_required_on_register'])
            ->mapWithKeys(fn (string $key) => [
                $key => SiteSetting::query()->where('key', $key)->sharedLock()->value('value'),
            ]);

        return $this->settingIsTrue($flags->get('email_verification_enabled'))
            && $this->settingIsTrue($flags->get('email_verification_required_on_register'))
            && $this->isMailConfigured()
            && $this->hasVerifiedMailTest()
            && $this->lockBackendLooksAvailable()
            && $this->transportLooksLive();
    }

    // ── Delivery-pipeline health (delegated) ─────────────────────────────────
    //
    // The probes themselves live in MailPipelineHealth. These thin forwarders
    // exist because this class is the entry point every caller and ~300 tests
    // already use, and because isEnforceableNow() below composes them into the
    // single question the rest of the application actually asks.

    public function lockBackendLooksAvailable(): bool
    {
        return $this->pipeline()->lockBackendLooksAvailable();
    }

    public function transportLooksLive(): bool
    {
        return $this->pipeline()->transportLooksLive();
    }

    public function mailConfigFingerprint(): string
    {
        return $this->pipeline()->mailConfigFingerprint();
    }

    public function recordSuccessfulMailTest(): void
    {
        $this->pipeline()->recordSuccessfulMailTest();
    }

    public function hasVerifiedMailTest(): bool
    {
        return $this->pipeline()->hasVerifiedMailTest();
    }

    public function mailTestVerifiedAt(): ?Carbon
    {
        return $this->pipeline()->mailTestVerifiedAt();
    }

    public function isMailConfigured(): bool
    {
        return $this->pipeline()->isMailConfigured();
    }

    /** @return array<int,string>|null */
    public function effectiveLeafMailers(): ?array
    {
        return $this->pipeline()->effectiveLeafMailers();
    }

    /**
     * Whether an EXISTING per-user obligation can be enforced RIGHT NOW:
     * feature enabled + usable mail + valid transport-test proof + a live
     * lock backend + live transport health.
     *
     * Deliberately WITHOUT the registration-wide "required" toggle: that
     * toggle only decides whether NEW registrations get stamped with the
     * obligation. An obligation a user already carries — stamped at
     * registration or imposed by an explicit admin «require_verification» —
     * stays enforceable while the pipeline fail-safes hold, even in optional
     * mode. The fail-safes still guarantee nobody is ever locked behind an
     * unproven or broken delivery pipeline.
     */
    public function isEnforceableNow(): bool
    {
        return $this->isEnabled()
            && $this->isMailConfigured()
            && $this->hasVerifiedMailTest()
            && $this->lockBackendLooksAvailable()
            && $this->transportLooksLive();
    }

    /**
     * Codes must comfortably outlive the job's claim-time delivery margin
     * (240s — the full supported SMTP exchange): a shorter TTL would make
     * EVERY delivery claim skip the code and required-mode users could never
     * receive a usable OTP.
     */
    public const MIN_TTL_MINUTES = 5;

    public function ttlMinutes(): int
    {
        return max(self::MIN_TTL_MINUTES, (int) SiteSetting::get('email_otp_ttl_minutes', self::CODE_TTL_MINUTES));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_max_attempts', self::MAX_ATTEMPTS));
    }

    public function resendCooldownSeconds(): int
    {
        return max(0, (int) SiteSetting::get('email_otp_resend_cooldown_seconds', self::RESEND_COOLDOWN_SEC));
    }

    /** Maximum OTP emails per user/email in a rolling 24h window. */
    public function dailyCap(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_daily_cap', self::DAILY_CAP));
    }

    // ── Resend gating ────────────────────────────────────────────────────────

    public function canResend(User $user): bool
    {
        return $this->resendCooldownRemaining($user) === 0;
    }

    /** Seconds until the user may request another code (0 = allowed now). */
    public function resendCooldownRemaining(User $user): int
    {
        // Only an ACTIONABLE code (the shared positive-list scope: unused,
        // unexpired, queued/sending/sent/accepted_pending) may hold the
        // cooldown — terminal or dead-end records (failed, dispatch_failed,
        // skipped) and expired codes left the user with nothing to act on,
        // even when an admin configures a cooldown longer than the TTL.
        $latest = EmailVerificationCode::query()
            ->actionableFor($user)
            ->latest('id')
            ->first();
        if ($latest === null) {
            return 0;
        }
        $availableAt = $latest->created_at->addSeconds($this->resendCooldownSeconds());
        $remaining = now()->diffInSeconds($availableAt, false);

        return $remaining > 0 ? (int) ceil($remaining) : 0;
    }

    /**
     * Remaining validity of the LATEST ACTIONABLE code (shared scope:
     * unused, unexpired, queued/sending/sent/accepted_pending) in whole
     * minutes (≥1), or null when none exists. The notice page must never
     * advertise a lifetime for a code the user can't realistically use —
     * failed/dispatch_failed/skipped records are not "active".
     */
    public function activeCodeRemainingMinutes(User $user): ?int
    {
        $latest = EmailVerificationCode::query()
            ->actionableFor($user)
            ->latest('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        return max(1, (int) floor(now()->diffInSeconds($latest->expires_at, false) / 60));
    }

    public function reachedDailyCap(User $user): bool
    {
        return EmailVerificationCode::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('email', $user->email);
        })
            ->where('created_at', '>=', now()->subDay())
            // Only records that never resulted in an attempted real delivery
            // are excluded from the cap: dispatch failures (never queued) and
            // skipped/superseded codes. DELIVERY failures made up to three
            // real transport attempts and DO count — otherwise repeated
            // transport failures could generate unlimited email batches.
            ->whereNotIn('send_status', [
                EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                EmailVerificationCode::SEND_STATUS_SKIPPED,
            ])
            ->count() >= $this->dailyCap();
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    /**
     * Generate, store (hashed) and queue an OTP email for the user.
     *
     * HONEST result contract: `email_sent` is true only when the message was
     * successfully handed to the queue (final delivery is tracked on the
     * record's send_status by the job). A failed dispatch or unusable mail
     * configuration returns status=error — never "code sent".
     *
     * @return array{status:string, message:string, email_sent?:bool}
     */
    public function requestCode(User $user, array $meta = []): array
    {
        // The administrator's disable switch is authoritative even for direct
        // POSTs to the resend endpoint — no records, no mail while disabled.
        if (! $this->isEnabled()) {
            return ['status' => 'error', 'message' => 'تایید ایمیل در حال حاضر غیرفعال است.', 'email_sent' => false];
        }

        if (! $this->isMailConfigured()) {
            return ['status' => 'error', 'message' => 'ارسال ایمیل در حال حاضر پیکربندی نشده است. لطفاً بعداً تلاش کنید یا با پشتیبانی تماس بگیرید.', 'email_sent' => false];
        }

        // Serialize issuance per user — bounded cache lock first, then the DB
        // row lock (consistent ordering: cache lock → user row → code rows).
        try {
            $outcome = $this->withUserLock($user->id, function () use ($user, $meta) {
                return DB::transaction(function () use ($user, $meta) {
                    // Bound the ROW-lock wait too: rows can be held by
                    // transactions outside our cache-lock protocol.
                    DatabaseLockTimeout::applyLocal();

                    // The LOCKED row is the single source of truth from here on —
                    // the caller's model may carry a stale email (e.g. a
                    // concurrent changeAddress committed in between).
                    $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
                    if ($lockedUser === null) {
                        return ['error' => 'کاربر یافت نشد. دوباره وارد حساب شوید.', 'status' => 'error'];
                    }

                    if (! $this->canResend($lockedUser)) {
                        return ['error' => 'برای ارسال مجدد کد کمی صبر کنید.', 'status' => 'rate_limited'];
                    }
                    if ($this->reachedDailyCap($lockedUser)) {
                        return ['error' => 'تعداد درخواست کد تایید در شبانه‌روز به حداکثر رسیده است. لطفاً فردا دوباره تلاش کنید.', 'status' => 'rate_limited'];
                    }

                    // The still-usable delivered code being SUPERSEDED (if
                    // any): its id travels out of the transaction so a
                    // DEFINITE pre-publication dispatch failure can restore
                    // it — the user must never lose an already-delivered
                    // usable code without receiving a replacement.
                    $supersededId = EmailVerificationCode::query()
                        ->actionableFor($lockedUser)
                        ->latest('id')
                        ->value('id');

                    // Single active code: invalidate every previous unused code first.
                    $this->invalidateCodes($lockedUser);

                    $code = (string) random_int(100000, 999999);

                    // Recorded as QUEUED up front: the sync driver (and a fast Redis
                    // worker) can run the job before this method resumes, and the
                    // job's terminal `sent` state must never be overwritten.
                    $record = EmailVerificationCode::create([
                        'user_id' => $lockedUser->id,
                        'email' => $lockedUser->email,
                        'code_hash' => Hash::make($code),
                        'expires_at' => now()->addMinutes($this->ttlMinutes()),
                        'attempts' => 0,
                        'send_status' => EmailVerificationCode::SEND_STATUS_QUEUED,
                        'ip_address' => $meta['ip'] ?? null,
                        'user_agent' => $meta['user_agent'] ?? null,
                    ]);

                    return ['record' => $record, 'code' => $code, 'superseded_id' => $supersededId];
                });
            });
        } catch (QueryException $e) {
            // The bounded row-lock wait fired: controlled busy semantics,
            // identical to cache-lock contention. Anything else stays fatal.
            if (! DatabaseLockTimeout::isLockTimeout($e)) {
                throw $e;
            }
            $outcome = null;
        }

        if ($outcome === null) {
            return ['status' => 'busy', 'message' => self::BUSY_MESSAGE, 'email_sent' => false];
        }
        if (isset($outcome['error'])) {
            return ['status' => $outcome['status'], 'message' => $outcome['error'], 'email_sent' => false];
        }

        /** @var EmailVerificationCode $record */
        $record = $outcome['record'];
        $code = $outcome['code'];

        try {
            // afterCommit inside the job: it is dispatched only after the
            // surrounding database transaction (if any) commits. The address
            // comes from the RECORD (written under the lock) — never from the
            // possibly-stale caller model. The superseded id travels in the
            // encrypted payload so failed() can restore the delivered code
            // after an async ZERO-transport exhaustion too.
            SendEmailOtpJob::dispatch(
                $record->id,
                $record->user_id,
                $record->email,
                $code,
                $this->ttlMinutes(),
                $outcome['superseded_id'] ?? null,
            );
        } catch (Throwable $e) {
            // NEVER pretend the code was sent when the dispatch failed — but
            // distinguish WHERE it failed. The stored error is a SANITIZED
            // category — raw transport/queue exception text can echo
            // credentials.
            $this->handleDispatchFailure($record, $outcome['superseded_id'] ?? null, $e);

            return ['status' => 'error', 'message' => 'ارسال ایمیل با خطا مواجه شد. لطفاً دوباره تلاش کنید.', 'email_sent' => false];
        }

        // PUBLICATION METADATA — a separate bounded write, strictly AFTER
        // dispatch() returned successfully: queue_published_at is the only
        // admissible queue-publication evidence (a merely-created row proves
        // nothing while dispatch may still fail). The handoff moment is
        // CAPTURED HERE, before the write can wait on any row lock — a
        // blocked UPDATE that resumes after a LATER dispatch failure must
        // record the true (older) handoff time, never a fresh now() that
        // would masquerade as post-outage recovery. NO status condition — a
        // fast worker or the sync driver may already have moved the row to a
        // terminal state, and publication still succeeded. Immutable via the
        // whereNull guard. If this stamp itself fails (including a bounded
        // row-lock timeout), dispatch already succeeded: never mark
        // dispatch_failed, never dispatch again — log a static sanitized
        // warning and leave NULL (health conservatively fails open, since
        // recovery cannot be proven by this issuance).
        $publishedAt = now();
        try {
            $this->recordQueuePublication($record->id, $publishedAt);
        } catch (Throwable $metadataError) {
            Log::warning('Email OTP queue-publication metadata could not be recorded', [
                'code_id' => $record->id,
                'outcome' => 'queue_published_at_left_null',
            ]);
        }

        // HONEST wording: the code is QUEUED for delivery — nothing has been
        // handed to a mail transport yet, so we never claim it was "sent".
        return ['status' => 'queued', 'message' => 'کد تایید در صف ارسال قرار گرفت.', 'email_sent' => true];
    }

    /**
     * The bounded queue-publication metadata write. $publishedAt is the
     * handoff moment captured IMMEDIATELY after dispatch() returned — never
     * evaluated inside this write, which can lawfully wait (bounded, via
     * SET LOCAL lock_timeout) on a held row lock and must not launder that
     * wait into a fresher publication time. Immutable via the whereNull
     * guard; no status condition — a fast worker or the sync driver may
     * already have finalized the row, and publication still succeeded.
     * Protected so tests can exercise requestCode()'s metadata-failure
     * containment.
     */
    protected function recordQueuePublication(int $codeId, Carbon $publishedAt): void
    {
        DB::transaction(function () use ($codeId, $publishedAt) {
            DatabaseLockTimeout::applyLocal();
            EmailVerificationCode::whereKey($codeId)
                ->whereNull('queue_published_at')
                ->update(['queue_published_at' => $publishedAt]);
        });
    }

    /**
     * dispatch() threw: classify WHERE the replacement issuance failed and
     * restore the superseded delivered code whenever the failure is provably
     * ZERO-transport. A dispatch exception is UNCONFIRMED publication — a
     * networked queue can accept the push and still return an error to the
     * producer, and a fast worker may already be claiming the row — so the
     * classification runs under the FULL protocol (per-user cache lock →
     * User row → OTP row, bounded) and re-reads the authoritative state
     * inside it; the row's fate is decided exactly once, serialized with
     * the worker:
     *
     *  - still `queued` UNDER THE LOCK: no worker has claimed. Closed as
     *    dispatch_failed + consumed — and if the push secretly reached the
     *    broker anyway, the late-delivered job finds a terminal
     *    non-claimable row and skips, so no email can follow the restore.
     *  - `dispatch_failed` with transport_attempted_at NULL: the sync
     *    driver executed inline and failed() already closed the replacement
     *    (contention exhausted before any claim/transport). Consume the
     *    dead replacement and restore.
     *  - anything else (`sending` under a worker's claim, `failed` after a
     *    real attempt, `sent`, `accepted_pending`, or transport_attempted_at
     *    set): a worker owns — or a transport may have been reached. NEVER
     *    finalized here and NEVER restored: the worker/failed() settles it.
     *  - lock contention or a cache outage: change NOTHING (fail open; the
     *    row is unpublished, so it can never become stall evidence, and the
     *    user's next resend retires it).
     */
    private function handleDispatchFailure(EmailVerificationCode $record, ?int $supersededId, Throwable $e): void
    {
        try {
            $outcome = $this->withUserLock($record->user_id, function () use ($record, $e) {
                return DB::transaction(function () use ($record, $e) {
                    DatabaseLockTimeout::applyLocal();

                    $user = User::whereKey($record->user_id)->lockForUpdate()->first();
                    $locked = EmailVerificationCode::whereKey($record->id)->lockForUpdate()->first();

                    if ($locked === null) {
                        return 'gone';
                    }

                    if ($locked->send_status === EmailVerificationCode::SEND_STATUS_QUEUED) {
                        $locked->forceFill([
                            'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                            'send_error' => MailFailure::summarize('queue dispatch failed', $e),
                            'used_at' => now(),
                            'delivery_finalized_at' => now(),
                            'delivery_config_fingerprint' => $this->mailConfigFingerprint(),
                        ])->save();

                        return 'zero_transport';
                    }

                    if (
                        $locked->send_status === EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED
                        && $locked->transport_attempted_at === null
                    ) {
                        // Consume the dead replacement (idempotent) so it can
                        // neither be advertised nor block the restore's
                        // newer-issuance guard.
                        EmailVerificationCode::whereKey($locked->id)
                            ->whereNull('used_at')
                            ->update(['used_at' => now()]);

                        return 'zero_transport';
                    }

                    return 'worker_owned';
                });
            });
        } catch (QueryException $qe) {
            if (! DatabaseLockTimeout::isLockTimeout($qe)) {
                throw $qe;
            }
            $outcome = null;
        }

        if ($outcome === 'zero_transport') {
            $this->restoreSupersededCode($supersededId, $record->user_id);
        }
    }

    /**
     * Restores the code a failed resend superseded — ONLY on a definite
     * ZERO-transport path (the replacement never reached any mail transport).
     * Re-validated under the standard locks: same user, unchanged address,
     * still unverified, consumed (by our supersession), unexpired, in an
     * actionable send status, and with NO newer unused actionable issuance
     * (a concurrent retry's replacement is the user's live path). IDEMPOTENT:
     * a second call finds used_at already NULL and changes nothing. The old
     * email is never re-dispatched. Best-effort: on contention or a cache
     * outage the user simply requests a fresh code.
     *
     * PUBLIC because SendEmailOtpJob::failed() restores after an async
     * zero-transport exhaustion; every safety check lives HERE, under locks.
     */
    public function restoreSupersededCode(?int $codeId, int $userId): void
    {
        if ($codeId === null) {
            return;
        }

        try {
            $this->withUserLock($userId, function () use ($codeId, $userId) {
                DB::transaction(function () use ($codeId, $userId) {
                    DatabaseLockTimeout::applyLocal();

                    $lockedUser = User::whereKey($userId)->lockForUpdate()->first();
                    $record = EmailVerificationCode::whereKey($codeId)->lockForUpdate()->first();

                    if (
                        $lockedUser === null
                        || $record === null
                        || $lockedUser->email_verified_at !== null
                        || $record->user_id !== $lockedUser->id
                        || strcasecmp((string) $record->email, (string) $lockedUser->email) !== 0
                        || $record->used_at === null
                        || $record->expires_at->isPast()
                        || ! in_array($record->send_status, EmailVerificationCode::ACTIONABLE_STATUSES, true)
                        // A NEWER unused ACTIONABLE issuance exists: between
                        // our dispatch_failed finalization and this lock, a
                        // concurrent retry saw no actionable code and created
                        // a replacement. Restoring now would leave TWO unused
                        // actionable rows — verify() always selects the
                        // newest, so the restored code would be dead weight
                        // that only mis-advertises a lifetime. The newer
                        // issuance is the user's live path; refuse. (Dead
                        // terminal replacements — dispatch_failed/failed —
                        // never block a restore.)
                        || EmailVerificationCode::where('user_id', $userId)
                            ->whereNull('used_at')
                            ->where('id', '>', $record->id)
                            ->whereIn('send_status', EmailVerificationCode::ACTIONABLE_STATUSES)
                            ->exists()
                    ) {
                        return;
                    }

                    $record->forceFill(['used_at' => null])->save();
                });
            });
        } catch (Throwable) {
            // Best-effort only — never turns a dispatch failure into a 500.
        }
    }

    // ── Per-user bounded serialization lock ──────────────────────────────────

    /** The distributed per-user lock key (shared with SendEmailOtpJob). */
    public static function userLockKey(int $userId): string
    {
        return 'email-verification:user:'.$userId;
    }

    /**
     * Run $callback while holding a BOUNDED per-user cache lock. Lock ordering
     * is always: cache lock → user row lock → code row locks (never reversed).
     *
     * Returns null when serialization could not be guaranteed — the lock wait
     * timed out (contention) OR the cache backend errored. Sensitive mutations
     * FAIL CLOSED on a Redis/cache outage rather than silently proceeding
     * unserialized; callers translate null into a Persian retry message and
     * make no partial changes. The TTL bounds an abandoned lock's lifetime,
     * and release happens in `finally`.
     */
    private function withUserLock(int $userId, callable $callback): mixed
    {
        try {
            $lock = Cache::lock(self::userLockKey($userId), self::LOCK_TTL_SECONDS);
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return null;
        } catch (Throwable) {
            // Cache backend unavailable — refuse the mutation (fail closed).
            return null;
        }

        try {
            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // TTL expiry is the backstop for a failed release.
            }
        }
    }

    // ── Verify ───────────────────────────────────────────────────────────────

    /**
     * Verify a submitted OTP for the user's CURRENT email address.
     *
     * @return array{status:string, message:string}
     */
    public function verify(User $user, string $code): array
    {
        // ATOMIC consumption: the USER row is locked first (serializing this
        // path against changeAddress(), which takes the same lock), then the
        // code row; the attempt counter increments under the lock, success
        // claims the code with a conditional update, and email_verified_at is
        // written in the SAME transaction — so a racing address change can
        // never end with the new, unproven address marked verified by a code
        // issued to the old one.
        try {
            $outcome = $this->withUserLock($user->id, fn () => DB::transaction(function () use ($user, $code) {
                DatabaseLockTimeout::applyLocal();

                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
                if ($lockedUser === null) {
                    return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
                }

                // The shared actionable scope WITHOUT the expiry filter: an
                // expired code still resolves so the user gets the specific
                // "expired — request a new one" message, not "not found".
                $record = EmailVerificationCode::query()
                    ->actionableFor($lockedUser, unexpiredOnly: false)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
                }

                if ($record->isExpired()) {
                    return ['status' => 'expired', 'message' => 'کد تایید منقضی شده است. یک کد جدید درخواست کنید.'];
                }

                if ($record->attempts >= $this->maxAttempts()) {
                    // Reachable only when an admin LOWERED the attempt limit
                    // below this record's count (the last permitted guess
                    // retires the code in the branch below): consume it now —
                    // an exhausted record must never stay actionable,
                    // advertising a lifetime and holding the resend cooldown
                    // while every further submission is doomed.
                    EmailVerificationCode::whereKey($record->id)
                        ->whereNull('used_at')
                        ->update(['used_at' => now()]);

                    return ['status' => 'too_many_attempts', 'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. یک کد جدید درخواست کنید.'];
                }

                $record->increment('attempts');

                if (! Hash::check($code, $record->code_hash)) {
                    // The LAST permitted guess retires the code immediately:
                    // an exhausted record must never stay actionable — it
                    // would advertise a lifetime and hold the resend cooldown
                    // while every further verify() is doomed (with a long
                    // cooldown that strands the user for no benefit).
                    if ($record->attempts >= $this->maxAttempts()) {
                        EmailVerificationCode::whereKey($record->id)
                            ->whereNull('used_at')
                            ->update(['used_at' => now()]);

                        return ['status' => 'too_many_attempts', 'message' => 'تعداد تلاش‌ها بیش از حد مجاز است. یک کد جدید درخواست کنید.'];
                    }

                    return ['status' => 'invalid', 'message' => 'کد تایید اشتباه است.'];
                }

                // Claim the single-use code: only one request can flip used_at.
                $claimed = EmailVerificationCode::whereKey($record->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);
                if ($claimed !== 1) {
                    return ['status' => 'error', 'message' => 'کد تایید یافت نشد. یک کد جدید درخواست کنید.'];
                }
                $this->invalidateCodes($lockedUser);

                $newlyVerified = false;
                if ($lockedUser->email_verified_at === null) {
                    $lockedUser->forceFill(['email_verified_at' => now()])->save();
                    $newlyVerified = true;
                }

                return [
                    'status' => 'verified',
                    'message' => 'ایمیل شما با موفقیت تایید شد.',
                    'newly_verified' => $newlyVerified,
                ];
            }));
        } catch (QueryException $e) {
            if (! DatabaseLockTimeout::isLockTimeout($e)) {
                throw $e;
            }
            $outcome = null;
        }

        if ($outcome === null) {
            return ['status' => 'busy', 'message' => self::BUSY_MESSAGE];
        }

        if (($outcome['newly_verified'] ?? false) === true) {
            event(new Verified($user->refresh()));
        }
        unset($outcome['newly_verified']);

        return $outcome;
    }

    // ── Address change ───────────────────────────────────────────────────────

    /**
     * Atomically switch the user to a NEW (already-validated, normalized)
     * address: every previous code dies, and the verification timestamp is
     * set per $markVerified (null = user self-service flow → unverified;
     * bool = trusted admin's explicit choice). Serialized by the same
     * per-user lock as issuance, verification and in-flight delivery — a
     * code issued to the old mailbox can never mark the new address
     * verified, and a job mid-SMTP-send blocks this briefly.
     *
     * Returns false on lock contention (cache OR bounded row-lock timeout):
     * the caller shows a retry message and NOTHING changed. A lost
     * email-uniqueness race (the DB index is the final authority) becomes a
     * normal ValidationException on $errorAttribute — the transaction rolled
     * back, so the old address, its verification timestamp, and every
     * existing OTP record are untouched; unrelated QueryExceptions rethrow.
     */
    public function changeAddressTo(
        User $user,
        string $email,
        ?bool $markVerified = null,
        string $errorAttribute = 'email',
    ): bool {
        $email = strtolower(trim($email));

        try {
            $result = $this->withUserLock($user->id, fn () => DB::transaction(function () use ($user, $email, $markVerified) {
                DatabaseLockTimeout::applyLocal();

                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
                if ($lockedUser === null) {
                    return false;
                }

                $this->invalidateCodes($lockedUser);
                $lockedUser->forceFill([
                    'email' => $email,
                    'email_verified_at' => $markVerified === true ? now() : null,
                ])->save();

                return true;
            }));
        } catch (QueryException $e) {
            if (DatabaseLockTimeout::isLockTimeout($e)) {
                return false;
            }
            EmailUniqueViolationProbe::translateOrRethrow($e, $errorAttribute);
        }

        if ($result === true) {
            $user->refresh();

            return true;
        }

        return false;
    }

    /**
     * ONE authoritative, ALL-OR-NOTHING mutation for trusted admin edits of a
     * user (Filament EditUser): every requested field — a possible email
     * change (with the admin's explicit verified/unverified choice and OTP
     * invalidation only when the address actually changes), is_admin, and all
     * other fillable attributes — commits in a SINGLE transaction under the
     * standard lock ordering (cache/Redis user lock → user row → OTP rows).
     * The cache lock is released only after the transaction ends; no
     * email-related mutation can ever commit while the rest of the edit
     * fails.
     *
     * $data special keys: `email` (normalized here), `is_admin` (trusted
     * explicit forceFill), `email_verification_action` (keep | mark_verified | require_verification).
     * Everything else goes through normal mass assignment — Filament already
     * excludes untouched password fields, so existing password semantics are
     * preserved.
     *
     * Failure modes: cache-lock contention or a bounded row-lock timeout →
     * ValidationException with the controlled busy message on `data.email`;
     * a lost email-uniqueness race → the standard Persian validation error on
     * `data.email`; every unrelated QueryException rethrows untouched. In all
     * failure cases the transaction rolled back — NO partial state survives.
     */
    public function applyAdminUpdate(User $user, array $data): User
    {
        try {
            $result = $this->withUserLock($user->id, fn () => DB::transaction(function () use ($user, $data) {
                DatabaseLockTimeout::applyLocal();

                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
                if ($lockedUser === null) {
                    return null;
                }

                // EXPLICIT verification semantics — never a raw timestamp
                // through mass assignment: `keep` (default) preserves the
                // current state, `mark_verified` stamps now(), and
                // `require_verification` clears it; both explicit actions
                // invalidate pending OTP codes. Unrelated field edits with
                // `keep` can never change the verification state.
                $action = (string) ($data['email_verification_action'] ?? 'keep');
                unset($data['email_verification_action'], $data['email_verified_at']);
                if (! in_array($action, ['keep', 'mark_verified', 'require_verification'], true)) {
                    throw ValidationException::withMessages([
                        'data.email_verification_action' => 'وضعیت تایید ایمیل انتخاب‌شده معتبر نیست.',
                    ]);
                }

                if (array_key_exists('is_admin', $data)) {
                    $lockedUser->forceFill(['is_admin' => (bool) $data['is_admin']]);
                    unset($data['is_admin']);
                }

                if (array_key_exists('email', $data)) {
                    $newEmail = strtolower(trim((string) $data['email']));
                    unset($data['email']);
                    if ($newEmail !== strtolower((string) $lockedUser->email)) {
                        // A CHANGED address demands an explicit policy — the
                        // old state must never silently carry over.
                        if ($action === 'keep') {
                            throw ValidationException::withMessages([
                                'data.email_verification_action' => 'برای تغییر آدرس ایمیل باید وضعیت تایید را صریحاً مشخص کنید: «تاییدشده» یا «نیازمند تایید».',
                            ]);
                        }
                        // Old codes die WITH this transaction (rolled back
                        // together on any failure).
                        $this->invalidateCodes($lockedUser);
                        $lockedUser->forceFill(['email' => $newEmail]);
                    }
                }

                if ($action === 'mark_verified') {
                    $this->invalidateCodes($lockedUser);
                    $lockedUser->forceFill(['email_verified_at' => now()]);
                } elseif ($action === 'require_verification') {
                    $this->invalidateCodes($lockedUser);
                    // The admin EXPLICITLY imposes the obligation: without the
                    // per-user marker, EnsureEmailIsVerified would bypass an
                    // account registered before/outside required mode and the
                    // action would only advertise enforcement. (Enforcement
                    // still also demands the global fail-safe policy —
                    // enabled + required + proven transport.)
                    $lockedUser->forceFill([
                        'email_verified_at' => null,
                        'email_verification_required_at_registration' => true,
                    ]);
                }

                $lockedUser->fill($data);
                $lockedUser->save();

                return $lockedUser;
            }));
        } catch (QueryException $e) {
            if (DatabaseLockTimeout::isLockTimeout($e)) {
                throw ValidationException::withMessages([
                    'data.email' => self::BUSY_MESSAGE,
                ]);
            }
            EmailUniqueViolationProbe::translateOrRethrow($e, 'data.email');
        }

        if ($result === null) {
            throw ValidationException::withMessages([
                'data.email' => self::BUSY_MESSAGE,
            ]);
        }

        return $result->refresh();
    }

    /** Mark every unused code for this user as consumed. */
    public function invalidateCodes(User $user): void
    {
        // Superseded-but-never-claimed queued rows will never see a transport
        // attempt (a later claim would only skip them): finalize them as
        // `skipped` NOW so the daily cap stays honest while a queue backlog
        // drains — repeated resends must not exhaust the allowance with rows
        // that produced zero deliveries.
        EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('send_status', EmailVerificationCode::SEND_STATUS_QUEUED)
            ->update([
                'send_status' => EmailVerificationCode::SEND_STATUS_SKIPPED,
                'delivery_finalized_at' => now(),
            ]);

        EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
