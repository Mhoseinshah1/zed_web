<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminMfa\AdminTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * TOTP primitive correctness: RFC vectors, drift bounds, atomic replay
 * prevention, encrypted storage, hidden serialization, fail-closed corruption.
 */
class AdminTotpCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    private function totp(): AdminTotpService
    {
        return app(AdminTotpService::class);
    }

    private function engine(): Google2FA
    {
        return app(Google2FA::class);
    }

    /** Enroll + confirm a credential and return [$user, $secret]. */
    private function enrolled(): array
    {
        $user = $this->admin();
        $enrollment = $this->totp()->startEnrollment($user);
        $result = $this->totp()->confirmEnrollment($user, $this->engine()->getCurrentOtp($enrollment['secret']));
        $this->assertNotNull($result);

        return [$user, $enrollment['secret']];
    }

    /** Rewind the consumed step so the current live code is usable again. */
    private function rewind(User $user, int $steps = 10): void
    {
        $cred = $this->totp()->credentialFor($user);
        $cred->forceFill(['last_verified_timestep' => max(0, (int) $cred->last_verified_timestep - $steps)])->save();
    }

    // ── RFC 6238 vectors ─────────────────────────────────────────────────────

    public function test_rfc6238_sha1_test_vectors_produce_the_expected_codes(): void
    {
        // RFC 6238 Appendix B, SHA-1 rows: ASCII secret "12345678901234567890"
        // (base32 below). The RFC lists 8-digit codes; the standard 6-digit
        // code is the same value mod 10^6 (last 6 digits).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $engine = $this->engine();

        // time=59s → counter 1 → 94287082; time=1111111109 → 07081804
        $this->assertSame('287082', $engine->oathTotp($secret, 1));
        $this->assertSame('081804', $engine->oathTotp($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', $engine->oathTotp($secret, intdiv(1111111111, 30)));
    }

    // ── Acceptance window ────────────────────────────────────────────────────

    public function test_wrong_malformed_stale_and_future_codes_all_fail(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->rewind($user);
        $engine = $this->engine();
        $now = $engine->getTimestamp();

        $current = $engine->oathTotp($secret, $now);
        $wrong = str_pad((string) ((((int) $current) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertNull($this->totp()->verifyAndConsume($user, $wrong), 'wrong code');
        $this->assertNull($this->totp()->verifyAndConsume($user, 'abc123'), 'malformed code');
        $this->assertNull($this->totp()->verifyAndConsume($user, '12345'), 'short code');
        $this->assertNull($this->totp()->verifyAndConsume($user, ''), 'empty code');
        $this->assertNull($this->totp()->verifyAndConsume($user, $engine->oathTotp($secret, $now - 5)), 'stale code (5 steps old)');
        $this->assertNull($this->totp()->verifyAndConsume($user, $engine->oathTotp($secret, $now + 5)), 'future code (5 steps ahead)');

        // Nothing was consumed by the failures.
        $before = $this->totp()->credentialFor($user)->last_verified_timestep;
        $this->assertNotNull($this->totp()->verifyAndConsume($user, $current), 'current code still valid after failures');
        $this->assertGreaterThan($before, $this->totp()->credentialFor($user)->last_verified_timestep);
    }

    public function test_minimal_permitted_drift_of_one_step_works(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->rewind($user);
        $engine = $this->engine();
        $now = $engine->getTimestamp();

        // One step behind (30s slow clock) is inside the documented window.
        $this->assertNotNull($this->totp()->verifyAndConsume($user, $engine->oathTotp($secret, $now - 1)));

        // Two steps behind is outside it.
        $this->rewind($user);
        $this->assertNull($this->totp()->verifyAndConsume($user, $engine->oathTotp($secret, $now - 2)));
    }

    // ── Replay prevention ────────────────────────────────────────────────────

    public function test_the_same_time_step_can_never_be_consumed_twice(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->rewind($user);
        $code = $this->engine()->getCurrentOtp($secret);

        $first = $this->totp()->verifyAndConsume($user, $code);
        $this->assertNotNull($first);

        // Identical code again — and any code for an older step — is refused.
        $this->assertNull($this->totp()->verifyAndConsume($user, $code));
        $this->assertNull($this->totp()->verifyAndConsume($user, $this->engine()->oathTotp($secret, $first - 1)));
    }

    public function test_enrollment_confirmation_consumes_its_code_for_login(): void
    {
        $user = $this->admin();
        $enrollment = $this->totp()->startEnrollment($user);
        $code = $this->engine()->getCurrentOtp($enrollment['secret']);

        $this->assertNotNull($this->totp()->confirmEnrollment($user, $code));

        // The very code that confirmed enrollment cannot also log in.
        $this->assertNull($this->totp()->verifyAndConsume($user, $code));
    }

    // ── Storage properties ───────────────────────────────────────────────────

    public function test_database_value_is_encrypted_and_serialization_hides_secrets(): void
    {
        [$user, $secret] = $this->enrolled();

        $raw = DB::table('admin_two_factor_credentials')->where('user_id', $user->id)->first();
        $this->assertNotSame('', (string) $raw->secret);
        $this->assertStringNotContainsString($secret, (string) $raw->secret, 'secret must never be stored in plaintext');

        $cred = $this->totp()->credentialFor($user);
        $serialized = json_encode($cred->toArray());
        $this->assertArrayNotHasKey('secret', $cred->toArray());
        $this->assertArrayNotHasKey('pending_secret', $cred->toArray());
        $this->assertArrayNotHasKey('recovery_codes', $cred->toArray());
        $this->assertStringNotContainsString($secret, $serialized);
    }

    public function test_corrupt_encrypted_data_fails_closed(): void
    {
        [$user] = $this->enrolled();

        DB::table('admin_two_factor_credentials')->where('user_id', $user->id)
            ->update(['secret' => 'not-a-valid-ciphertext']);

        $this->assertFalse($this->totp()->hasConfirmedCredential($user), 'corrupt credential is unusable');
        $this->assertNull($this->totp()->verifyAndConsume($user, '123456'), 'corrupt credential never verifies');
        // And it is NOT treated as missing-therefore-bypassed anywhere: the
        // panel middleware requires a VALID marker, which needs a confirmed,
        // decryptable credential.
    }

    // ── Recovery codes ───────────────────────────────────────────────────────

    public function test_recovery_codes_are_stored_only_as_hashes_and_consume_once(): void
    {
        $user = $this->admin();
        $enrollment = $this->totp()->startEnrollment($user);
        $result = $this->totp()->confirmEnrollment($user, $this->engine()->getCurrentOtp($enrollment['secret']));

        $codes = $result['codes'];
        $this->assertCount(AdminTotpService::RECOVERY_CODE_COUNT, $codes);

        // Plaintext never reaches the database.
        $raw = (array) DB::table('admin_two_factor_credentials')->where('user_id', $user->id)->first();
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, json_encode($raw));
        }

        // Stored values are bcrypt hashes of the displayed codes.
        $hashes = $this->totp()->credentialFor($user)->recovery_codes;
        $this->assertTrue(Hash::check($codes[0], $hashes[0]) || collect($hashes)->contains(fn ($h) => Hash::check($codes[0], $h)));

        // One-time consumption.
        $this->assertTrue($this->totp()->consumeRecoveryCode($user, $codes[0]));
        $this->assertFalse($this->totp()->consumeRecoveryCode($user, $codes[0]), 'a recovery code cannot be reused');
        $this->assertSame(AdminTotpService::RECOVERY_CODE_COUNT - 1, $this->totp()->recoveryCodesRemaining($user));
    }

    public function test_regenerating_recovery_codes_invalidates_the_previous_set(): void
    {
        $user = $this->admin();
        $enrollment = $this->totp()->startEnrollment($user);
        $old = $this->totp()->confirmEnrollment($user, $this->engine()->getCurrentOtp($enrollment['secret']))['codes'];

        $new = $this->totp()->regenerateRecoveryCodes($user);
        $this->assertNotNull($new);

        $this->assertFalse($this->totp()->consumeRecoveryCode($user, $old[0]), 'old codes die on regeneration');
        $this->assertTrue($this->totp()->consumeRecoveryCode($user, $new[0]));
    }

    // ── Pending enrollment lifecycle ─────────────────────────────────────────

    public function test_abandoned_pending_enrollment_expires_and_cannot_confirm(): void
    {
        $user = $this->admin();
        $enrollment = $this->totp()->startEnrollment($user);

        $this->travel(AdminTotpService::PENDING_TTL_MINUTES + 1)->minutes();

        $this->assertNull(
            $this->totp()->confirmEnrollment($user, $this->engine()->getCurrentOtp($enrollment['secret'])),
            'an abandoned pending secret must not confirm'
        );

        // A fresh start issues a NEW secret.
        $again = $this->totp()->startEnrollment($user);
        $this->assertNotSame($enrollment['secret'], $again['secret']);
    }

    public function test_only_admins_may_enroll(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->expectException(\RuntimeException::class);
        $this->totp()->startEnrollment($user);
    }

    public function test_replacement_keeps_old_factor_until_new_one_confirms_then_rotates_version(): void
    {
        [$user, $oldSecret] = $this->enrolled();
        $this->rewind($user);
        $oldVersion = $this->totp()->credentialFor($user)->version();

        // Start replacement: old factor still fully live.
        $replacement = $this->totp()->startEnrollment($user);
        $this->assertTrue($this->totp()->hasConfirmedCredential($user));
        $this->assertNotNull($this->totp()->verifyAndConsume($user, $this->engine()->getCurrentOtp($oldSecret)));

        // Confirm with the NEW device: promoted, version re-stamped, old
        // secret dead, recovery codes replaced.
        $this->travel(61)->seconds(); // fresh step for the new confirmation
        $result = $this->totp()->confirmEnrollment($user, $this->engine()->getCurrentOtp($replacement['secret']));
        $this->assertNotNull($result);

        $cred = $this->totp()->credentialFor($user);
        $this->assertNotSame($oldVersion, $cred->version(), 'version must rotate on replacement');

        $this->rewind($user);
        $this->assertNull($this->totp()->verifyAndConsume($user, $this->engine()->getCurrentOtp($oldSecret)), 'old factor dead after replacement');
    }
}
