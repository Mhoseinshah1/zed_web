<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Email\EmailVerificationService;
use App\Services\Email\MailPipelineHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Guards the seam created by extracting `MailPipelineHealth` out of
 * `EmailVerificationService`.
 *
 * The extraction is behaviour-preserving, and the ~250 existing email tests are
 * what prove that — they call the same public methods on the same class and
 * still pass unchanged. What they do NOT prove is that the seam itself stays
 * honest afterwards, which is where a refactor like this usually rots:
 *
 *   • a threshold re-exported from `EmailVerificationService` silently drifting
 *     from the one the probes actually read, so a test tuned to the re-exported
 *     value asserts against a different number than production uses;
 *   • a delegated method quietly growing a second implementation on the
 *     forwarding side;
 *   • `isEnforceableNow()` losing one of the five signals it composes, which
 *     would enforce verification against an unproven or dead pipeline.
 *
 * Each of those is a real defect that no existing test would notice.
 */
class MailPipelineHealthTest extends TestCase
{
    use RefreshDatabase;

    /** Thresholds referenced through the old class must BE the new ones. */
    public function test_the_re_exported_thresholds_cannot_drift(): void
    {
        $this->assertSame(MailPipelineHealth::MAIL_TEST_PROOF_MAX_DAYS, EmailVerificationService::MAIL_TEST_PROOF_MAX_DAYS);
        $this->assertSame(MailPipelineHealth::OUTAGE_WINDOW_MINUTES, EmailVerificationService::OUTAGE_WINDOW_MINUTES);
        $this->assertSame(MailPipelineHealth::OUTAGE_MIN_FAILURES, EmailVerificationService::OUTAGE_MIN_FAILURES);
        $this->assertSame(MailPipelineHealth::STALLED_QUEUE_MINUTES, EmailVerificationService::STALLED_QUEUE_MINUTES);
        $this->assertSame(MailPipelineHealth::ABANDONED_SENDING_MINUTES, EmailVerificationService::ABANDONED_SENDING_MINUTES);
        $this->assertSame(MailPipelineHealth::STALL_MARKER_KEY, EmailVerificationService::STALL_MARKER_KEY);
    }

    /**
     * Every forwarded method must return exactly what the collaborator returns.
     *
     * Comparing two LIVE calls would prove nothing: in the test environment the
     * real probes and a reimplementation can easily agree by accident, and an
     * earlier version of this test passed with `isMailConfigured()` hardcoded to
     * `true`. The double therefore returns values nothing else would produce.
     */
    public function test_every_delegated_probe_returns_the_collaborator_result(): void
    {
        $double = new class extends MailPipelineHealth
        {
            public function lockBackendLooksAvailable(): bool
            {
                return false;
            }

            public function transportLooksLive(): bool
            {
                return false;
            }

            public function mailConfigFingerprint(): string
            {
                return 'sentinel-fingerprint-9f3a';
            }

            public function hasVerifiedMailTest(): bool
            {
                return true;
            }

            public function isMailConfigured(): bool
            {
                return true;
            }

            public function effectiveLeafMailers(): ?array
            {
                return ['sentinel-mailer'];
            }

            public function mailTestVerifiedAt(): ?Carbon
            {
                return Carbon::parse('2019-03-04 05:06:07');
            }
        };

        $service = new EmailVerificationService($double);

        $this->assertFalse($service->lockBackendLooksAvailable());
        $this->assertFalse($service->transportLooksLive());
        $this->assertSame('sentinel-fingerprint-9f3a', $service->mailConfigFingerprint());
        $this->assertTrue($service->hasVerifiedMailTest());
        $this->assertTrue($service->isMailConfigured());
        $this->assertSame(['sentinel-mailer'], $service->effectiveLeafMailers());
        $this->assertSame('2019-03-04 05:06:07', $service->mailTestVerifiedAt()?->format('Y-m-d H:i:s'));

        // recordSuccessfulMailTest() returns void, so prove the call lands.
        $recorder = new class extends MailPipelineHealth
        {
            public int $calls = 0;

            public function recordSuccessfulMailTest(): void
            {
                $this->calls++;
            }
        };
        (new EmailVerificationService($recorder))->recordSuccessfulMailTest();
        $this->assertSame(1, $recorder->calls);
    }

    /** The forwarders must actually forward — an injected double is obeyed. */
    public function test_an_injected_pipeline_is_the_one_that_answers(): void
    {
        $double = new class extends MailPipelineHealth
        {
            public function transportLooksLive(): bool
            {
                return false;
            }

            public function isMailConfigured(): bool
            {
                return false;
            }
        };

        $service = new EmailVerificationService($double);

        $this->assertFalse($service->transportLooksLive());
        $this->assertFalse($service->isMailConfigured());
    }

    /**
     * `isEnforceableNow()` is the one question the rest of the application
     * asks, and it is only safe because it composes ALL five signals. Losing
     * any one of them would enforce verification against a pipeline that is
     * switched off, unconfigured, unproven, or provably dead — the exact way a
     * user gets locked out of their own account.
     */
    public function test_enforceability_fails_closed_when_any_single_signal_is_down(): void
    {
        foreach (['transportLooksLive', 'isMailConfigured', 'hasVerifiedMailTest', 'lockBackendLooksAvailable'] as $failing) {
            $double = new class extends MailPipelineHealth
            {
                public string $failing = '';

                public function transportLooksLive(): bool
                {
                    return $this->failing !== 'transportLooksLive';
                }

                public function isMailConfigured(): bool
                {
                    return $this->failing !== 'isMailConfigured';
                }

                public function hasVerifiedMailTest(): bool
                {
                    return $this->failing !== 'hasVerifiedMailTest';
                }

                public function lockBackendLooksAvailable(): bool
                {
                    return $this->failing !== 'lockBackendLooksAvailable';
                }
            };
            $double->failing = $failing;

            $service = new EmailVerificationService($double);

            // Verification itself is switched on; only the pipeline signal fails.
            SiteSetting::set('email_verification_enabled', '1');

            $this->assertFalse(
                $service->isEnforceableNow(),
                "isEnforceableNow() must fail closed when {$failing}() reports a problem",
            );
        }

        // …and with every signal healthy AND the feature enabled, it enforces —
        // otherwise the assertions above would pass vacuously.
        $healthy = new class extends MailPipelineHealth
        {
            public function transportLooksLive(): bool
            {
                return true;
            }

            public function isMailConfigured(): bool
            {
                return true;
            }

            public function hasVerifiedMailTest(): bool
            {
                return true;
            }

            public function lockBackendLooksAvailable(): bool
            {
                return true;
            }
        };

        SiteSetting::set('email_verification_enabled', '1');
        $this->assertTrue((new EmailVerificationService($healthy))->isEnforceableNow());
    }

    /**
     * The moved write must be atomic and immediately readable.
     *
     * `persistStallMarker()` moved into this class carrying a raw
     * `SiteSetting::query()->upsert(...)`. It now goes through
     * `SiteSetting::upsertValue()`, which keeps the atomic behaviour the
     * concurrent-detection path depends on while giving a single place for a
     * later change to add reader invalidation.
     */
    /**
     * The registration-stamping fail-safe composes the SAME five signals, and
     * it was unguarded: dropping `transportLooksLive()` from
     * `captureRequiredPolicyForRegistration()` failed no test.
     *
     * It decides whether a NEW registration is stamped with a verification
     * obligation. Stamping during a delivery outage creates an account that is
     * obligated to verify through a pipeline that cannot deliver — a lockout
     * created at signup, which is why every signal has to be consulted.
     */
    public function test_registration_stamping_fails_closed_when_any_signal_is_down(): void
    {
        SiteSetting::set('email_verification_enabled', 'true');
        SiteSetting::set('email_verification_required_on_register', 'true');

        foreach (['transportLooksLive', 'isMailConfigured', 'hasVerifiedMailTest', 'lockBackendLooksAvailable'] as $failing) {
            $double = new class extends MailPipelineHealth
            {
                public string $failing = '';

                public function transportLooksLive(): bool
                {
                    return $this->failing !== 'transportLooksLive';
                }

                public function isMailConfigured(): bool
                {
                    return $this->failing !== 'isMailConfigured';
                }

                public function hasVerifiedMailTest(): bool
                {
                    return $this->failing !== 'hasVerifiedMailTest';
                }

                public function lockBackendLooksAvailable(): bool
                {
                    return $this->failing !== 'lockBackendLooksAvailable';
                }
            };
            $double->failing = $failing;

            $this->assertFalse(
                (new EmailVerificationService($double))->captureRequiredPolicyForRegistration(),
                "registration must not be stamped while {$failing}() reports a problem",
            );
        }

        // …and with every signal healthy it DOES stamp, or the four assertions
        // above would pass vacuously.
        $healthy = new class extends MailPipelineHealth
        {
            public function transportLooksLive(): bool
            {
                return true;
            }

            public function isMailConfigured(): bool
            {
                return true;
            }

            public function hasVerifiedMailTest(): bool
            {
                return true;
            }

            public function lockBackendLooksAvailable(): bool
            {
                return true;
            }
        };

        $this->assertTrue((new EmailVerificationService($healthy))->captureRequiredPolicyForRegistration());
    }

    public function test_the_stall_marker_write_is_atomic_and_immediately_readable(): void
    {
        SiteSetting::set(MailPipelineHealth::STALL_MARKER_KEY, '');
        $this->assertSame('', SiteSetting::get(MailPipelineHealth::STALL_MARKER_KEY));

        $persist = new \ReflectionMethod(MailPipelineHealth::class, 'persistStallMarker');
        $persist->setAccessible(true);
        $persist->invoke(app(MailPipelineHealth::class), '2026-07-29T00:00:00+00:00');

        $this->assertSame(
            '2026-07-29T00:00:00+00:00',
            SiteSetting::get(MailPipelineHealth::STALL_MARKER_KEY),
            'a stall-marker write must be visible to the very next read',
        );
    }

    public function test_the_moved_stall_marker_write_uses_no_raw_builder_upsert(): void
    {
        // A structural guard, because the failure mode is silent: a raw
        // `SiteSetting::query()->upsert(...)` still works, so nothing goes red
        // if it creeps back — it just stops being the one sanctioned write path
        // that a later change can add invalidation to.
        //
        // CODE only: the docblocks deliberately name the banned form to explain
        // why it is banned, and matching those would be a self-inflicted false
        // positive.
        $source = implode('', array_map(
            fn ($token) => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all(file_get_contents((new \ReflectionClass(MailPipelineHealth::class))->getFileName())),
        ));

        $this->assertStringNotContainsString(
            'SiteSetting::query()->upsert(',
            $source,
            'the stall marker must be written through SiteSetting::upsertValue()',
        );
        $this->assertStringContainsString('SiteSetting::upsertValue(', $source);
    }

    /**
     * The collaborator must stay OPTIONAL and lazily resolved.
     *
     * Existing tests build this service with
     * `Mockery::mock(...)->makePartial()`, which never runs the constructor, and
     * `app(EmailVerificationService::class)` resolves it with no arguments. A
     * promoted, non-nullable, defaultless constructor property breaks both —
     * the first with an uninitialised-property error on first use, the second
     * with an ArgumentCountError at resolution.
     */
    public function test_the_collaborator_is_optional_and_lazily_resolved(): void
    {
        $constructor = (new \ReflectionClass(EmailVerificationService::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertSame(0, $constructor->getNumberOfRequiredParameters(), 'the collaborator must be optional');

        $property = (new \ReflectionClass(EmailVerificationService::class))->getProperty('pipeline');
        $this->assertTrue($property->getType()?->allowsNull(), 'the property must be nullable so it can start unset');
        $this->assertFalse($property->isReadOnly(), 'readonly would block lazy assignment');

        // Constructed with no arguments, and with the constructor bypassed
        // entirely, a delegated call still works.
        $this->assertIsBool((new EmailVerificationService)->isMailConfigured());

        $bypassed = (new \ReflectionClass(EmailVerificationService::class))->newInstanceWithoutConstructor();
        $this->assertIsBool($bypassed->isMailConfigured());
    }

    /** A Mockery partial mock — the real-world shape — still delegates. */
    public function test_a_partial_mock_can_still_use_the_collaborator(): void
    {
        /** @var EmailVerificationService $service */
        $service = \Mockery::mock(EmailVerificationService::class)->makePartial();

        $this->assertIsBool($service->isMailConfigured());
        $this->assertIsBool($service->transportLooksLive());
    }
}
