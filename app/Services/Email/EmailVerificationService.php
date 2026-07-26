<?php

namespace App\Services\Email;

use App\Jobs\SendEmailOtpJob;
use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\DatabaseLockTimeout;
use App\Support\EmailUniqueViolationProbe;
use App\Support\MailFailure;
use Aws\Ses\SesClient;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
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
    // Defaults — overridden by admin settings at runtime.
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SEC = 60;

    public const DAILY_CAP = 10;

    /** Bounded wait for the per-user serialization lock (seconds). */
    public const LOCK_WAIT_SECONDS = 3;

    /** TTL of the per-user lock — an abandoned lock can never outlive this. */
    public const LOCK_TTL_SECONDS = 10;

    /** A successful transport test is trusted for this many days. */
    public const MAIL_TEST_PROOF_MAX_DAYS = 30;

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
            && $this->hasVerifiedMailTest();
    }

    /** Truthiness matching SiteSetting::get's coercion ('true' or numeric). */
    private function settingIsTrue(?string $value): bool
    {
        return $value === 'true' || (is_numeric($value) && (int) $value !== 0);
    }

    /**
     * Whether an EXISTING per-user obligation can be enforced RIGHT NOW:
     * feature enabled + usable mail + valid transport-test proof.
     *
     * Deliberately WITHOUT the registration-wide "required" toggle: that
     * toggle only decides whether NEW registrations get stamped with the
     * obligation. An obligation a user already carries — stamped at
     * registration or imposed by an explicit admin «require_verification» —
     * stays enforceable while the mail fail-safes hold, even in optional
     * mode. The fail-safes still guarantee nobody is ever locked behind an
     * unproven mailer.
     */
    public function isEnforceableNow(): bool
    {
        return $this->isEnabled()
            && $this->isMailConfigured()
            && $this->hasVerifiedMailTest();
    }

    // ── Transport-test proof ─────────────────────────────────────────────────

    /**
     * A deterministic hash of the CURRENT mail configuration, combining:
     *
     *  1. the non-secret OPERATIONAL fingerprint — mailer name, resolved
     *     transport graph, SMTP host/port/scheme/encryption + whether a
     *     username is present, sendmail path, SES region, Mailgun domain,
     *     From identity; and
     *  2. an APP_KEY-keyed HMAC DIGEST of the credential material used by the
     *     effective transports (SMTP username/password/MAIL_URL, SES
     *     key/secret/token, Postmark/Resend keys, Mailgun secret) — so a
     *     rotated or mistyped credential invalidates the stored proof, while
     *     no plaintext credential (and nothing reversible or unkeyed) is ever
     *     persisted, logged, or displayed. The HMAC input exists in memory
     *     only; only the final combined hash is stored. Rotating APP_KEY
     *     therefore also invalidates old proofs safely.
     */
    public function mailConfigFingerprint(): string
    {
        $default = (string) config('mail.default');
        $transports = $this->effectiveTransports($default) ?? [];
        ksort($transports);

        $input = [
            'default' => $default,
            'graph' => $transports,
            // The EXACT composite structure — node types (failover vs
            // roundrobin), nesting, and child ORDER. The flattened leaf map
            // above is order-insensitive, so without this a re-wired routing
            // policy (failover→roundrobin, reordered/renested children) would
            // keep accepting a proof that never exercised the new policy.
            'topology' => $this->mailerTopology($default),
            'from_address' => (string) config('mail.from.address'),
            'from_name' => (string) config('mail.from.name'),
            'mailers' => [],
        ];

        foreach ($transports as $mailerName => $transport) {
            $input['mailers'][$mailerName] = match ($transport) {
                'smtp' => [
                    'transport' => 'smtp',
                    'host' => (string) config("mail.mailers.{$mailerName}.host"),
                    'port' => (int) config("mail.mailers.{$mailerName}.port"),
                    'scheme' => (string) config("mail.mailers.{$mailerName}.scheme"),
                    'encryption' => (string) config("mail.mailers.{$mailerName}.encryption"),
                    // The EHLO identity affects deliverability: servers can
                    // reject a changed local_domain (defaults from APP_URL).
                    'local_domain' => (string) config("mail.mailers.{$mailerName}.local_domain"),
                    'has_username' => (string) config("mail.mailers.{$mailerName}.username") !== '',
                ],
                'sendmail' => [
                    'transport' => 'sendmail',
                    'path' => (string) config("mail.mailers.{$mailerName}.path"),
                ],
                'ses', 'ses-v2' => [
                    'transport' => $transport,
                    'region' => (string) config('services.ses.region'),
                ],
                'mailgun' => [
                    'transport' => 'mailgun',
                    'domain' => (string) config('services.mailgun.domain'),
                ],
                default => ['transport' => $transport],
            };
        }

        $input['secret_digest'] = $this->secretConfigDigest($transports);

        return hash('sha256', (string) json_encode($input));
    }

    /**
     * Keyed, non-reversible digest of the credential material the effective
     * transports actually use. The canonical input distinguishes null / empty
     * / present values, carries current secret values IN MEMORY ONLY, and is
     * reduced to a single HMAC keyed by an APP_KEY-derived key — never stored,
     * logged, or returned anywhere except folded into the overall fingerprint.
     *
     * @param  array<string,string>  $transports  mailer name ⇒ transport
     */
    private function secretConfigDigest(array $transports): string
    {
        $material = [];

        foreach ($transports as $mailerName => $transport) {
            $material[$mailerName] = match ($transport) {
                'smtp' => [
                    'username' => $this->secretComponent(config("mail.mailers.{$mailerName}.username")),
                    'password' => $this->secretComponent(config("mail.mailers.{$mailerName}.password")),
                    // MAIL_URL can embed user:pass@host credentials.
                    'url' => $this->secretComponent(config("mail.mailers.{$mailerName}.url")),
                ],
                'ses', 'ses-v2' => [
                    'key' => $this->secretComponent(config('services.ses.key')),
                    'secret' => $this->secretComponent(config('services.ses.secret')),
                    'token' => $this->secretComponent(config('services.ses.token')),
                ],
                'postmark' => ['key' => $this->secretComponent(config('services.postmark.key'))],
                'resend' => ['key' => $this->secretComponent(config('services.resend.key'))],
                'mailgun' => ['secret' => $this->secretComponent(config('services.mailgun.secret'))],
                // sendmail/log/array carry no credentials.
                default => [],
            };
        }
        ksort($material);

        // Derive the HMAC key from APP_KEY (never use APP_KEY directly), so
        // an APP_KEY rotation invalidates old proofs by design.
        $derivedKey = hash_hmac('sha256', 'zedproxy.email-mail-test-proof.v1', (string) config('app.key'));

        return hash_hmac('sha256', (string) json_encode($material), $derivedKey);
    }

    /** Canonical null/empty/present marker; present carries the raw value (memory only). */
    private function secretComponent(mixed $value): array
    {
        if ($value === null) {
            return ['state' => 'null'];
        }
        if ((string) $value === '') {
            return ['state' => 'empty'];
        }

        return ['state' => 'present', 'value' => (string) $value];
    }

    /** Persist the proof AFTER a transport accepted the dedicated test email. */
    public function recordSuccessfulMailTest(): void
    {
        SiteSetting::set('email_mail_test_fingerprint', $this->mailConfigFingerprint());
        SiteSetting::set('email_mail_test_verified_at', now()->toIso8601String());
    }

    /**
     * Whether a successful transport test exists FOR THE CURRENT configuration
     * and is recent enough. A fingerprint mismatch (any operational config
     * change) or an expired proof invalidates it automatically.
     */
    public function hasVerifiedMailTest(): bool
    {
        $fingerprint = (string) SiteSetting::get('email_mail_test_fingerprint', '');
        $verifiedAt = $this->mailTestVerifiedAt();

        if ($fingerprint === '' || $verifiedAt === null) {
            return false;
        }
        if (! hash_equals($fingerprint, $this->mailConfigFingerprint())) {
            return false;
        }

        return $verifiedAt->gt(now()->subDays(self::MAIL_TEST_PROOF_MAX_DAYS));
    }

    public function mailTestVerifiedAt(): ?Carbon
    {
        $raw = (string) SiteSetting::get('email_mail_test_verified_at', '');
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether outbound mail looks genuinely deliverable. In production the
     * `log` and `array` mailers are NOT configuration — they silently discard
     * mail, so treating them as configured would strand unverified users.
     * COMPOSITE mailers (failover/roundrobin) are expanded: the repository's
     * default `failover` chains smtp → log, and a log fallback would report a
     * "successful" send without delivering the OTP — so any composite that
     * contains a non-delivery transport is rejected in production too.
     */
    public function isMailConfigured(): bool
    {
        // null = the mailer graph itself is invalid (undefined name — e.g. the
        // classic `smpt` typo — a cycle, or an empty composite). Conservative:
        // an invalid graph is NEVER "configured".
        $transports = $this->effectiveTransports((string) config('mail.default'));
        if ($transports === null || $transports === []) {
            return false;
        }

        if (app()->environment('production') && array_intersect($transports, ['log', 'array']) !== []) {
            return false;
        }

        $from = (string) config('mail.from.address');
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // EVERY effective transport must look usable — a failover chain is
        // only as deliverable as its members.
        foreach ($transports as $mailerName => $transport) {
            if (! $this->transportLooksUsable((string) $mailerName, $transport)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Expand a mailer name into its effective transports (mailer-name ⇒
     * transport), unwrapping failover/roundrobin composites recursively with
     * CYCLE detection via the visited set.
     *
     * Returns null when the graph is invalid: undefined mailer name, a
     * composite that references itself (directly or indirectly), an empty
     * composite, or a member without a transport. Callers must treat null as
     * NOT configured — Laravel's mail manager would reject it at send time.
     *
     * @param  array<int,string>  $visited
     * @return array<string,string>|null
     */
    private function effectiveTransports(string $mailer, array $visited = []): ?array
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return null;
        }
        $visited[] = $mailer;

        $conf = config("mail.mailers.{$mailer}");
        if (! is_array($conf)) {
            return null;
        }

        $transport = (string) ($conf['transport'] ?? '');
        if ($transport === '') {
            return null;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = array_values(array_filter((array) ($conf['mailers'] ?? [])));
            if ($children === []) {
                return null;
            }
            $out = [];
            foreach ($children as $child) {
                $childTransports = $this->effectiveTransports((string) $child, $visited);
                if ($childTransports === null) {
                    return null;
                }
                $out += $childTransports;
            }

            return $out;
        }

        return [$mailer => $transport];
    }

    /**
     * The ORDER- and NESTING-preserving structure of a mailer graph, for the
     * fingerprint only: composite nodes keep their exact type (failover vs
     * roundrobin) and child sequence; leaves keep mailer name + transport.
     * Invalid graphs collapse to a marker (isMailConfigured rejects them
     * independently). Purely non-secret.
     *
     * @param  array<int,string>  $visited
     * @return array<string,mixed>
     */
    private function mailerTopology(string $mailer, array $visited = []): array
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return ['invalid' => $mailer];
        }
        $visited[] = $mailer;

        $conf = config("mail.mailers.{$mailer}");
        $transport = is_array($conf) ? (string) ($conf['transport'] ?? '') : '';
        if ($transport === '') {
            return ['invalid' => $mailer];
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            return [
                'type' => $transport,
                'name' => $mailer,
                'children' => array_map(
                    fn ($child) => $this->mailerTopology((string) $child, $visited),
                    array_values(array_filter((array) ($conf['mailers'] ?? []))),
                ),
            ];
        }

        return ['mailer' => $mailer, 'transport' => $transport];
    }

    /**
     * Per-transport plausibility check WITHOUT exposing any credential values:
     * only presence/shape is inspected, nothing is returned or logged.
     * Unknown transports fail conservatively.
     */
    private function transportLooksUsable(string $mailerName, string $transport): bool
    {
        return match ($transport) {
            // Non-delivery transports: rejected in production by the caller;
            // in dev/test they are intentionally accepted.
            'log', 'array' => true,
            'smtp' => (string) config("mail.mailers.{$mailerName}.host") !== ''
                && (int) config("mail.mailers.{$mailerName}.port") > 0,
            'sendmail' => (string) config("mail.mailers.{$mailerName}.path", '/usr/sbin/sendmail -bs') !== '',
            // config/services.php has a region DEFAULT (us-east-1), so region
            // alone proves nothing — require the explicit key pair this
            // repository's configuration actually reads. Each API transport
            // ALSO requires its runtime package: Laravel's mail manager throws
            // when constructing a transport whose bridge isn't installed, so
            // credentials without the package are still an unusable mailer.
            'ses', 'ses-v2' => class_exists(SesClient::class)
                && (string) config('services.ses.key') !== ''
                && (string) config('services.ses.secret') !== ''
                && (string) config('services.ses.region') !== '',
            'postmark' => class_exists(PostmarkTransportFactory::class)
                && (string) config('services.postmark.key') !== '',
            'resend' => class_exists(\Resend::class)
                && (string) config('services.resend.key') !== '',
            'mailgun' => class_exists(MailgunTransportFactory::class)
                && (string) config('services.mailgun.secret') !== ''
                && (string) config('services.mailgun.domain') !== '',
            default => false,
        };
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) SiteSetting::get('email_otp_ttl_minutes', self::CODE_TTL_MINUTES));
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

                    return ['record' => $record, 'code' => $code];
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
            // possibly-stale caller model.
            SendEmailOtpJob::dispatch($record->id, $record->user_id, $record->email, $code, $this->ttlMinutes());
        } catch (Throwable $e) {
            // NEVER pretend the code was sent when the dispatch failed — but
            // distinguish WHERE it failed. On the sync driver, dispatch()
            // executes the handler inline: a transport exception propagates
            // here AFTER the job's own failed() hook already recorded the
            // honest `failed` outcome (a REAL delivery attempt, which must
            // keep counting toward the daily cap). Only when the record is
            // still `queued` did publication itself fail — no handler ever
            // ran — and only then is it marked dispatch_failed and consumed
            // (so a never-queued attempt can't hold the cooldown). The stored
            // error is a SANITIZED category — raw transport/queue exception
            // text can echo credentials.
            $record->refresh();
            if ($record->send_status === EmailVerificationCode::SEND_STATUS_QUEUED) {
                $record->forceFill([
                    'send_status' => EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
                    'send_error' => MailFailure::summarize('queue dispatch failed', $e),
                    'used_at' => now(),
                ])->save();
            }

            return ['status' => 'error', 'message' => 'ارسال ایمیل با خطا مواجه شد. لطفاً دوباره تلاش کنید.', 'email_sent' => false];
        }

        // HONEST wording: the code is QUEUED for delivery — nothing has been
        // handed to a mail transport yet, so we never claim it was "sent".
        return ['status' => 'queued', 'message' => 'کد تایید در صف ارسال قرار گرفت.', 'email_sent' => true];
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
        EmailVerificationCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
