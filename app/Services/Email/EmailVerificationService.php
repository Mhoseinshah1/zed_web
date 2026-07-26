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

    /** Live-outage detection window for transportLooksLive(). */
    public const OUTAGE_WINDOW_MINUTES = 30;

    /** Consecutive finalized transport failures that signal a live outage. */
    public const OUTAGE_MIN_FAILURES = 3;

    /**
     * An UNEXPIRED `queued` row this old proves no worker is consuming: a
     * live worker claims within seconds (contention retries within ~100s),
     * so publication-succeeded-but-never-claimed is pipeline downtime that
     * dispatch_failed (publication errors only) cannot see. Deliberately
     * BELOW the 5-minute TTL floor: the evidence self-clears when the code
     * expires, so a permanently lost job can never wedge enforcement open
     * forever — while a live stall keeps producing fresh stuck rows.
     */
    public const STALLED_QUEUE_MINUTES = 4;

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
     * The per-user LOCK BACKEND is part of the delivery pipeline: with it
     * down (a dedicated cache Redis connection or ACL failing while the app
     * and database stay up), requestCode() and verify() both fail closed
     * BEFORE any health outcome row exists — an obligated user could neither
     * obtain nor submit a code while still being redirected. A unique random
     * probe key distinguishes backend unavailability from ordinary per-user
     * contention: it can never contend, so any failure here is the backend
     * itself. Fail-open, like every other pipeline signal.
     */
    public function lockBackendLooksAvailable(): bool
    {
        try {
            $probe = Cache::lock('email-verification:lock-probe:'.bin2hex(random_bytes(8)), 1);
            if (! $probe->get()) {
                return false;
            }
            try {
                $probe->release();
            } catch (Throwable) {
                // TTL (1s) reclaims the probe key.
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * LIVE transport-health signal: static configuration shape and a ≤30-day
     * proof cannot see an endpoint that died WITHOUT any config change. This
     * inspects actual recent delivery outcomes and reports an outage only on
     * strong evidence — the latest OUTAGE_MIN_FAILURES finalized transport
     * outcomes inside the window are ALL failures (any success among them
     * clears it, as does sparse traffic). Fail-open by design: during a
     * detected outage enforcement pauses and registrations are not stamped,
     * exactly like an unconfigured mailer; the moment a delivery succeeds,
     * enforcement resumes. Worst case of a false positive is verification
     * temporarily degrading to optional — never a locked-out user.
     */
    public function transportLooksLive(): bool
    {
        // Windowed and ordered by delivery_finalized_at — the DEDICATED,
        // immutable delivery-outcome timestamp every terminal transition
        // stamps — never issuance id (jobs finalize out of issuance order)
        // and never updated_at (a general mutation time: verify() or
        // invalidateCodes() touching an old `sent` row must not make it look
        // like a freshly finalized success during a live outage).
        // STALLED workers first: queue publication succeeded but nothing
        // consumes (Supervisor stopped, null driver) — those rows never
        // finalize, so the outcome scan below cannot see the outage. An
        // unconsumed `queued` row past the stall threshold is downtime, and
        // the evidence deliberately OUTLIVES the code's expiry: an expired
        // stalled row proves nothing about queue recovery, and discarding it
        // would resume enforcement against a still-dead worker (each retried
        // resend burning the user's daily cap). Only positive proof that a
        // worker consumed a job AFTER the newest stalled row clears it — a
        // WORKER-stamped terminal outcome (sent / accepted_pending / failed;
        // never web-stamped skipped or dispatch_failed) finalized after the
        // stall, or the stalled row itself leaving `queued` when a recovered
        // worker claims it. The user's next resend also retires the old row
        // (invalidateCodes → skipped) and re-arms a fresh probe.
        $stalledQueuedSince = EmailVerificationCode::query()
            ->where('send_status', EmailVerificationCode::SEND_STATUS_QUEUED)
            ->whereNull('used_at')
            ->where('created_at', '<=', now()->subMinutes(self::STALLED_QUEUE_MINUTES))
            ->max('created_at');
        if ($stalledQueuedSince !== null) {
            $stalledQueuedSince = Carbon::parse($stalledQueuedSince);
            $workerConsumedAfterStall = EmailVerificationCode::query()
                ->whereIn('send_status', [
                    EmailVerificationCode::SEND_STATUS_SENT,
                    EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
                    EmailVerificationCode::SEND_STATUS_FAILED,
                ])
                // Queue-consumption proof, so no fingerprint or error-category
                // scoping: even a recipient-bounced `failed` row proves the
                // worker pulled a job off the queue after the stall began.
                ->where('delivery_finalized_at', '>', $stalledQueuedSince)
                ->exists();
            if (! $workerConsumedAfterStall) {
                return false;
            }
        }

        $currentFingerprint = $this->mailConfigFingerprint();

        $recentOutcomes = EmailVerificationCode::query()
            ->where('delivery_finalized_at', '>=', now()->subMinutes(self::OUTAGE_WINDOW_MINUTES))
            ->where(function ($q) use ($currentFingerprint) {
                // QUEUE-publication outcomes are independent of the MAIL
                // configuration: a broken queue blocks codes no matter which
                // transport is configured, so dispatch failures count
                // regardless of fingerprint — changing a mail setting (which
                // rotates the fingerprint) must never hide a live queue
                // outage.
                $q->where('send_status', EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED)
                    // TRANSPORT outcomes count only when PROVABLY produced
                    // under the current configuration: a long-lived worker
                    // still running a replaced config — including
                    // pre-fingerprint code during a rolling deployment, whose
                    // rows carry NULL — must not finalize a stale failure
                    // after the admin certified the new one and re-suspend
                    // enforcement. Ignoring unscoped legacy rows costs at
                    // most one health window of old evidence.
                    ->orWhere(function ($q2) use ($currentFingerprint) {
                        $q2->where('delivery_config_fingerprint', $currentFingerprint)
                            ->where(function ($q3) {
                                $q3->whereIn('send_status', [
                                    EmailVerificationCode::SEND_STATUS_SENT,
                                    EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
                                ])
                                    // Only ENDPOINT/configuration failures
                                    // count. Recipient-scoped rejections are
                                    // excluded entirely — three deliberately
                                    // rejectable addresses must not fabricate
                                    // a "site-wide outage" and switch required
                                    // verification off for everyone.
                                    ->orWhere(function ($q4) {
                                        $q4->where('send_status', EmailVerificationCode::SEND_STATUS_FAILED)
                                            ->where(function ($q5) {
                                                $q5->whereNull('send_error')
                                                    ->orWhere('send_error', 'not like', '%recipient_rejected%');
                                            });
                                    });
                            });
                    });
            })
            ->orderByDesc('delivery_finalized_at')
            ->orderByDesc('id')
            ->limit(self::OUTAGE_MIN_FAILURES)
            ->get(['send_status', 'delivery_finalized_at']);

        if ($recentOutcomes->count() < self::OUTAGE_MIN_FAILURES) {
            return true;
        }

        $anySuccess = $recentOutcomes->contains(
            fn ($outcome) => ! in_array($outcome->send_status, [
                EmailVerificationCode::SEND_STATUS_FAILED,
                EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
            ], true),
        );
        if ($anySuccess) {
            return true;
        }

        // A QUEUE outage among the failures can never be cleared by a mail
        // test: the admin test sends SYNCHRONOUSLY and proves nothing about
        // queue publication — only an actual successful queued delivery (or
        // the window expiring) may clear that category.
        $anyDispatchFailure = $recentOutcomes->contains(
            fn ($outcome) => $outcome->send_status === EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED,
        );
        if ($anyDispatchFailure) {
            return false;
        }

        // Pure TRANSPORT failures may belong to an endpoint the operator has
        // since repaired: a successful admin transport test — which exercises
        // EVERY delivery leaf and is fingerprint-bound to the CURRENT
        // configuration — run AFTER the newest failure is positive live
        // evidence (test sends never create OTP outcome rows, so without
        // this the old failures would keep enforcement suspended for the
        // rest of the window).
        $newestFailureAt = $recentOutcomes->first()->delivery_finalized_at;
        $testAt = $this->mailTestVerifiedAt();

        return $testAt !== null
            && $testAt->gt($newestFailureAt)
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
                    // Operational too: shrinking the per-operation timeout
                    // after a successful test can make normal OTP sends time
                    // out — the old proof must not survive the change.
                    'timeout' => (int) config("mail.mailers.{$mailerName}.timeout"),
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

        // Non-delivery transports are acceptable ONLY in explicit local/
        // testing environments. Anything else — production, staging, a typo'd
        // APP_ENV — must reject them: a `log`/`array` "success" would record
        // a valid proof while no user can receive an OTP.
        if (! app()->environment(['local', 'testing']) && array_intersect($transports, ['log', 'array']) !== []) {
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
     * The DELIVERY LEAVES of the current mailer graph (mailer-name ⇒
     * transport), or null when the graph is invalid. Composite policies
     * (failover/roundrobin) route different sends to different leaves, so a
     * transport test must exercise EVERY leaf — one healthy child accepting
     * a single test proves nothing about its siblings.
     *
     * @return array<string,string>|null
     */
    public function effectiveLeafMailers(): ?array
    {
        return $this->effectiveTransports((string) config('mail.default'));
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
            // Non-delivery transports: rejected outside local/testing by the
            // caller; in those two environments they are intentionally usable.
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
            // SES accepts EITHER an explicit static key pair OR neither set —
            // on EC2/ECS/EKS the AWS SDK's default provider chain (IAM role,
            // env, shared config) authenticates without config values, and
            // the synchronous certification test is the real verdict. A
            // HALF-configured pair (one of key/secret missing) stays
            // rejected as a misconfiguration.
            'ses', 'ses-v2' => class_exists(SesClient::class)
                && (string) config('services.ses.region') !== ''
                && (
                    ((string) config('services.ses.key') !== '' && (string) config('services.ses.secret') !== '')
                    || ((string) config('services.ses.key') === '' && (string) config('services.ses.secret') === '')
                ),
            'postmark' => class_exists(PostmarkTransportFactory::class)
                && (string) config('services.postmark.key') !== '',
            // Laravel's MailManager instantiates the SDK's `\Resend` entry
            // class (`use Resend;` + Resend::client()); accept a namespaced
            // variant too so an SDK relocation can never wrongly reject a
            // working Resend setup.
            'resend' => (class_exists(\Resend::class) || class_exists(\Resend\Resend::class))
                && (string) config('services.resend.key') !== '',
            'mailgun' => class_exists(MailgunTransportFactory::class)
                && (string) config('services.mailgun.secret') !== ''
                && (string) config('services.mailgun.domain') !== '',
            default => false,
        };
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
                    'delivery_finalized_at' => now(),
                    'delivery_config_fingerprint' => $this->mailConfigFingerprint(),
                ])->save();

                // DEFINITE pre-publication failure: the replacement never
                // existed for delivery, so the code it superseded is restored
                // — the user must not lose an already-delivered usable OTP
                // and receive nothing in exchange.
                $this->restoreSupersededCode($outcome['superseded_id'] ?? null, $record->user_id);
            }

            return ['status' => 'error', 'message' => 'ارسال ایمیل با خطا مواجه شد. لطفاً دوباره تلاش کنید.', 'email_sent' => false];
        }

        // HONEST wording: the code is QUEUED for delivery — nothing has been
        // handed to a mail transport yet, so we never claim it was "sent".
        return ['status' => 'queued', 'message' => 'کد تایید در صف ارسال قرار گرفت.', 'email_sent' => true];
    }

    /**
     * Restores the code a failed resend superseded — ONLY on the definite
     * pre-publication path (the replacement was never handed to any worker).
     * Re-validated under the standard locks: same user, unchanged address,
     * still unverified, consumed (by our supersession), unexpired, and in an
     * actionable send status. Best-effort: on contention or a cache outage
     * the user simply requests a fresh code.
     */
    private function restoreSupersededCode(?int $codeId, int $userId): void
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
