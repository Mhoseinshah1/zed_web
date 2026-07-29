<?php

namespace App\Services\Email;

use App\Models\EmailVerificationCode;
use App\Models\SiteSetting;
use Aws\Ses\SesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Throwable;

/**
 * Whether the EMAIL DELIVERY PIPELINE can currently be trusted.
 *
 * This is a different question from "is email verification switched on", and
 * it used to live inside EmailVerificationService, where it was roughly 40%
 * of the class and shared nothing with the OTP lifecycle beyond being
 * consulted by isEnforceableNow(). Splitting it out is a pure move: every
 * method below is byte-for-byte the code that ran before, and
 * EmailVerificationService now delegates to it.
 *
 * It answers three independent questions.
 *
 * **Is the configuration usable at all?** mailConfigFingerprint(),
 * isMailConfigured(), effectiveLeafMailers() and their helpers walk the
 * mailer topology (including failover/roundrobin fan-out) and decide whether
 * each leaf transport is actually instantiable with the credentials present.
 *
 * **Has it ever been proven to work?** recordSuccessfulMailTest() /
 * hasVerifiedMailTest() / mailTestVerifiedAt() hold a time-boxed proof that a
 * real send succeeded under the CURRENT configuration fingerprint, so editing
 * the configuration invalidates the proof.
 *
 * **Is it working right now?** transportLooksLive() and
 * lockBackendLooksAvailable() inspect recent delivery outcomes, stalled queue
 * publications and abandoned worker claims. Static configuration and a
 * 30-day-old proof cannot see an endpoint that died without any config change.
 *
 * Every signal here is deliberately FAIL-OPEN: a false negative pauses
 * enforcement and degrades verification to optional, which is recoverable. A
 * false positive would lock a real user behind a delivery pipeline that cannot
 * deliver, which is not.
 */
class MailPipelineHealth
{
    /** A successful transport test is trusted for this many days. */
    public const MAIL_TEST_PROOF_MAX_DAYS = 30;

    /** Live-outage detection window for transportLooksLive(). */
    public const OUTAGE_WINDOW_MINUTES = 30;

    /** Consecutive finalized transport failures that signal a live outage. */
    public const OUTAGE_MIN_FAILURES = 3;

    /**
     * A publication this old that NO worker ever consumed proves nothing is
     * draining the queue: a live worker claims within seconds (contention
     * retries within ~100s), so publication-succeeded-but-never-consumed is
     * pipeline downtime that dispatch_failed (publication errors only)
     * cannot see. The evidence clock is queue_published_at — the CONFIRMED
     * dispatch handoff — never created_at: a row whose publication was
     * never confirmed may not be in the queue at all and can never become
     * stalled-worker evidence. The evidence covers rows still `queued` AND
     * rows the web side retired unconsumed (skipped with a NULL
     * delivery_claimed_at — sub-threshold resends must not launder the
     * signal), deliberately OUTLIVES the code's expiry, and clears only on
     * positive proof a worker consumed a job after the stall became
     * detectable (see transportLooksLive()).
     */
    public const STALLED_QUEUE_MINUTES = 4;

    /**
     * A `sending` claim this old with NO finalization proves the claiming
     * worker died mid-delivery and nothing resumed the job: on a live
     * queue, an interrupted attempt is redelivered at the redelivery
     * horizon (retry_after 300s > job timeout 240s) and the retry either
     * RE-CLAIMS the row (fresh delivery_claimed_at) or finalizes it — so
     * in healthy operation a claim stamp is never older than ~300s. Eight
     * minutes adds margin for slow polls and longer SQS visibility
     * timeouts; a false positive merely pauses enforcement (fail-open)
     * until the redelivered attempt finalizes.
     */
    public const ABANDONED_SENDING_MINUTES = 8;

    /**
     * Persisted detection timestamp of a stalled/abandoned pipeline — the
     * moment the evidence became STALE (its row timestamp plus the
     * applicable threshold), not the row's raw creation/claim time. The
     * row-level evidence is mutable — a resend retires the aged queued row
     * (skipped) and replaces it with one too fresh to trip the probes — so
     * the detection itself is stored and holds the outage open until a
     * worker-stamped finalization NEWER than it proves recovery. Cleared
     * (set to '') on that proof; never displayed or admin-editable.
     */
    public const STALL_MARKER_KEY = 'email_pipeline_stalled_since';

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
        // worker claims it.
        // The evidence is any PUBLICATION no worker ever consumed — not only
        // rows still `queued`: a resend faster than the stall threshold
        // retires the previous row (web-stamped skipped, delivery_claimed_at
        // NULL) before it can age into the queued-only probe, which would
        // let sub-threshold resends keep enforcement active forever against
        // dead workers. A worker that later consumes a retired row's job
        // stamps delivery_claimed_at on it (claim() / markSkipped), removing
        // it from this set — so "retired then consumed" (healthy) never
        // counts, while "published and never consumed" always does.
        // Only a CONFIRMED publication can prove a dead worker: a row whose
        // dispatch never completed (queue_published_at NULL — dispatch still
        // in flight, failed, or its metadata stamp was lost) was possibly
        // never in the queue at all, so its age proves nothing about
        // consumption. The evidence clock is queue_published_at, never
        // created_at.
        $stalledQueuedSince = EmailVerificationCode::query()
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('send_status', EmailVerificationCode::SEND_STATUS_QUEUED)
                        ->whereNull('used_at');
                })->orWhere(function ($q2) {
                    $q2->where('send_status', EmailVerificationCode::SEND_STATUS_SKIPPED);
                });
            })
            ->whereNull('delivery_claimed_at')
            ->whereNotNull('queue_published_at')
            ->where('queue_published_at', '<=', now()->subMinutes(self::STALLED_QUEUE_MINUTES))
            ->max('queue_published_at');

        // ABANDONED claims are the same outage one step later: a worker
        // claimed (queued → sending), was killed before finalizing, and
        // nothing resumed the reserved job — the row carries no
        // delivery_finalized_at, so the outcome scan below is equally blind
        // to it. delivery_claimed_at is refreshed on every (re-)claim and
        // never bumped by web-side mutations, so a stamp past the abandoned
        // threshold is proof the pipeline stopped mid-delivery. (Pre-column
        // legacy rows carry NULL and are ignored.)
        $abandonedSendingSince = EmailVerificationCode::query()
            ->where('send_status', EmailVerificationCode::SEND_STATUS_SENDING)
            // Deliberately NOT filtered on used_at: a resend (or a verify)
            // marks the row used without changing its status, and an
            // abandoned claim stays evidence of a worker dying mid-delivery
            // regardless of what the web side did to the row afterwards.
            ->whereNull('delivery_finalized_at')
            ->where('delivery_claimed_at', '<=', now()->subMinutes(self::ABANDONED_SENDING_MINUTES))
            ->max('delivery_claimed_at');

        // A RESEND must not launder the evidence: invalidateCodes() retires
        // the aged queued row (→ web-stamped skipped) and its replacement is
        // too fresh to trip either probe for another stall threshold — a
        // window in which enforcement would resume and registrations would
        // be stamped against a still-dead worker. The first detection is
        // therefore PERSISTED as a marker (the evidence timestamp) and holds
        // the outage across resends and row transitions until a worker
        // proves recovery; a corrupt marker value is ignored (fail-open).
        $marker = SiteSetting::get(self::STALL_MARKER_KEY, '');
        $markerAt = null;
        if (is_string($marker) && $marker !== '') {
            try {
                $markerAt = Carbon::parse($marker);
            } catch (Throwable) {
                $markerAt = null;
            }
        }

        // Recovery must postdate the moment the evidence became STALE — the
        // row's timestamp PLUS its threshold — never the raw creation/claim
        // time: a worker that finalized some other job right after this row
        // was queued and then died with everyone else would otherwise leave
        // a "proof" permanently newer than the evidence, masking the outage
        // forever. A worker alive at the detection moment would have
        // consumed the evidence row itself.
        $stalledDetectedAt = collect([
            $stalledQueuedSince === null
                ? null
                : Carbon::parse($stalledQueuedSince)->addMinutes(self::STALLED_QUEUE_MINUTES),
            $abandonedSendingSince === null
                ? null
                : Carbon::parse($abandonedSendingSince)->addMinutes(self::ABANDONED_SENDING_MINUTES),
            $markerAt,
        ])->filter()->max();
        if ($stalledDetectedAt !== null) {
            $workerConsumedAfterStall = EmailVerificationCode::query()
                ->where(function ($q) use ($stalledDetectedAt) {
                    // Queue-consumption proof, so no fingerprint or
                    // error-category scoping: even a recipient-bounced
                    // `failed` row proves the worker pulled a job off the
                    // queue after the stall became detectable.
                    $q->where(function ($q2) use ($stalledDetectedAt) {
                        $q2->whereIn('send_status', [
                            EmailVerificationCode::SEND_STATUS_SENT,
                            EmailVerificationCode::SEND_STATUS_ACCEPTED_PENDING,
                            EmailVerificationCode::SEND_STATUS_FAILED,
                        ])->where('delivery_finalized_at', '>', $stalledDetectedAt);
                    })
                        // A WORKER-stamped skip is consumption too: claim()/
                        // markSkipped stamp delivery_claimed_at when the
                        // worker executes a job whose row was already
                        // retired — nothing was sent, but the queue is
                        // demonstrably being drained. (Web-side skips carry
                        // NULL and can never satisfy this.)
                        ->orWhere(function ($q2) use ($stalledDetectedAt) {
                            $q2->where('send_status', EmailVerificationCode::SEND_STATUS_SKIPPED)
                                ->where('delivery_claimed_at', '>', $stalledDetectedAt);
                        });
                })
                ->exists();
            if (! $workerConsumedAfterStall) {
                $persisted = $stalledDetectedAt->format('Y-m-d H:i:s');
                if ($marker !== $persisted) {
                    $this->persistStallMarker($persisted);
                }

                return false;
            }
            if ($marker !== '') {
                $this->persistStallMarker('');
            }
        }

        // PUBLICATION failures are judged FIRST and INDEPENDENTLY of the
        // truncated transport scan below: enough late-finalizing pre-outage
        // jobs can fill the latest-N outcome set with `sent` rows and expel
        // every dispatch_failed from it — completion of old jobs says
        // nothing about publishing today. Only REAL publication failures
        // count (queue_published_at NULL): a dispatch_failed row whose
        // publication succeeded (failed() exhausting a published job before
        // any transport) is a processing outcome, not queue evidence. Queue
        // recovery is proven ONLY by a CONFIRMED publication — a row whose
        // queue_published_at postdates the newest failure. Never admissible
        // as publication proof: a merely-created queued row (dispatch may
        // still fail), an old job completing, created_at by itself, or a
        // mail test (it sends synchronously, bypassing the queue).
        $newestDispatchFailureAt = EmailVerificationCode::query()
            ->where('send_status', EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED)
            ->whereNull('queue_published_at')
            ->where('delivery_finalized_at', '>=', now()->subMinutes(self::OUTAGE_WINDOW_MINUTES))
            ->max('delivery_finalized_at');
        if ($newestDispatchFailureAt !== null) {
            $publishedAfterFailure = EmailVerificationCode::query()
                ->where('queue_published_at', '>', Carbon::parse($newestDispatchFailureAt))
                ->exists();
            if (! $publishedAfterFailure) {
                return false;
            }
            // Publication demonstrably works again — any dispatch failures
            // in the scan below are stale, and the transport verdict stands
            // on its own evidence.
        }

        $currentFingerprint = $this->mailConfigFingerprint();

        $recentOutcomes = EmailVerificationCode::query()
            ->where('delivery_finalized_at', '>=', now()->subMinutes(self::OUTAGE_WINDOW_MINUTES))
            ->where(function ($q) use ($currentFingerprint) {
                // QUEUE-publication outcomes are independent of the MAIL
                // configuration: a broken queue blocks codes no matter which
                // transport is configured, so REAL publication failures
                // (queue_published_at NULL) count regardless of fingerprint —
                // changing a mail setting (which rotates the fingerprint)
                // must never hide a live queue outage. A dispatch_failed row
                // whose publication SUCCEEDED (a published job exhausted
                // before transport) is neither queue nor transport evidence
                // and is excluded from the scan entirely.
                $q->where(function ($qd) {
                    $qd->where('send_status', EmailVerificationCode::SEND_STATUS_DISPATCH_FAILED)
                        ->whereNull('queue_published_at');
                })
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

        // Pure TRANSPORT failures may belong to an endpoint the operator has
        // since repaired: a successful admin transport test — which exercises
        // EVERY delivery leaf and is fingerprint-bound to the CURRENT
        // configuration — run AFTER the newest failure is positive live
        // evidence (test sends never create OTP outcome rows, so without
        // this the old failures would keep enforcement suspended for the
        // rest of the window). Any dispatch failures in the set were proven
        // stale above (publication has since succeeded), so the test may
        // clear what remains: pure transport evidence.
        $newestFailureAt = $recentOutcomes->first()->delivery_finalized_at;
        $testAt = $this->mailTestVerifiedAt();

        return $testAt !== null
            && $testAt->gt($newestFailureAt)
            && $this->hasVerifiedMailTest();
    }

    /**
     * Race-safe stall-marker write for the REQUEST path. Concurrent first
     * detections all reach this while the row does not exist yet;
     * SiteSetting::set()'s read-then-insert would let one racer die on the
     * unique `key` constraint and turn a fail-open health check into a 500
     * on a dashboard request or registration. An atomic upsert makes the
     * losing writer update instead — both racers persist the same
     * detection.
     *
     * Goes through SiteSetting::upsertValue() rather than a raw
     * `SiteSetting::query()->upsert(...)`: a builder write fires no model
     * events, so the raw form leaves the scoped settings reader stale and a
     * read-after-write in the same lifecycle returns the previous marker.
     * This extraction originally carried the raw call across from
     * EmailVerificationService, which would have silently undone that fix on
     * merge.
     */
    private function persistStallMarker(string $value): void
    {
        SiteSetting::upsertValue(self::STALL_MARKER_KEY, $value);
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
     *
     * ONE proof policy for BOTH configuration sources: this reads the
     * EFFECTIVE runtime configuration, which EmailTransportSettingsService
     * resolves before it is evaluated — when the admin-panel SMTP override is
     * enabled, the default mailer is the dedicated `managed_smtp` mailer and
     * its values (including the override source itself, via the default
     * mailer name and topology) flow into this fingerprint exactly like
     * environment-backed values do. No parallel fingerprint system exists.
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

        // OTP DELIVERY TIME BUDGET — single effective leaf only. Every bound
        // in the pipeline (job timeout 240s, per-user lock TTL 270s,
        // redelivery horizon 300s, claim margin = job timeout) is sized for
        // ONE complete transport exchange. A failover/roundrobin graph with
        // several leaves can chain multiple full exchanges inside a single
        // attempt — two SMTP leaves at the 20s per-operation cap already
        // exceed the whole-job budget — risking worker termination
        // mid-transport, lock expiry during SMTP I/O, queue redelivery while
        // a previous worker is still sending, and duplicate OTP delivery.
        // Until a validator proves the COMPLETE worst-case timing invariant
        // (per-leaf exchange cost incl. API client timeouts < job timeout <
        // lock TTL < redelivery horizon, and claim-time validity ≥ job
        // timeout), multi-leaf graphs are rejected for OTP delivery — never
        // silently reduced to their first child, and never certified as if
        // the composite were safe. A composite that RESOLVES to exactly one
        // leaf (e.g. failover with a single child) is accepted: it performs
        // at most one exchange.
        if (count($transports) > 1) {
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
}
