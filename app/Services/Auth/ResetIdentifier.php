<?php

namespace App\Services\Auth;

use App\Support\PhoneNumber;

/**
 * THE single identifier-canonicalization boundary for the password-reset
 * flow: account lookup AND rate-limiter identity both consume exactly this
 * result, so equivalent representations of one account (email case /
 * whitespace differences; local, +98, 98 and 0098 Iranian mobile forms —
 * everything PhoneNumber::normalize() accepts) always map to ONE canonical
 * identity, one account, and one rate-limit bucket.
 *
 * The limiter subject is an APP_KEY-backed HMAC of the canonical value —
 * raw identifiers never become cache keys. Invalid input still yields a
 * deterministic non-reversible subject so the public behavior stays
 * uniform.
 */
final class ResetIdentifier
{
    /**
     * @return array{type:'email'|'phone'|'invalid', canonical:string}
     */
    public static function canonicalize(?string $raw): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            return ['type' => 'invalid', 'canonical' => ''];
        }

        if (str_contains($trimmed, '@')) {
            return ['type' => 'email', 'canonical' => strtolower($trimmed)];
        }

        $normalized = PhoneNumber::normalize($trimmed);
        if ($normalized !== null) {
            return ['type' => 'phone', 'canonical' => $normalized];
        }

        return ['type' => 'invalid', 'canonical' => ''];
    }

    /** Non-reversible APP_KEY-keyed limiter subject for a submitted value. */
    public static function limiterSubject(?string $raw): string
    {
        $canonical = self::canonicalize($raw);

        $material = $canonical['type'] === 'invalid'
            ? 'invalid:'.trim((string) $raw)
            : $canonical['type'].':'.$canonical['canonical'];

        return hash_hmac('sha256', $material, (string) config('app.key'));
    }
}
