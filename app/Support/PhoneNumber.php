<?php

namespace App\Support;

/**
 * Iranian mobile phone normalization.
 *
 * Accepts 09xxxxxxxxx, +989xxxxxxxxx, 989xxxxxxxxx, 00989xxxxxxxxx and
 * normalizes to the canonical +989xxxxxxxxx form.
 */
class PhoneNumber
{
    /**
     * Normalize an Iranian mobile number to +989xxxxxxxxx.
     * Returns null when the input is not a valid Iranian mobile number.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Keep only digits (drop spaces, dashes, plus, etc.)
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // Strip international prefixes down to the 10-digit national number (9xxxxxxxxx).
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Must now be exactly 10 digits starting with 9 (Iranian mobile).
        if (! preg_match('/^9\d{9}$/', $digits)) {
            return null;
        }

        return '+98' . $digits;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
