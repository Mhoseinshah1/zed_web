<?php

namespace App\Services\Auth;

use App\Models\SiteSetting;

/**
 * Typed accessor over the login brute-force throttle site settings.
 *
 * Values live in the database (admin-configurable) and fall back to secure
 * defaults when unset. Mirrors the existing ReferralSettings/OTP settings style.
 */
class LoginThrottleSettings
{
    /** Failed attempts allowed before the account/IP pair is locked. */
    public const MAX_ATTEMPTS = 5;

    /** How long the lock lasts, in seconds. */
    public const LOCKOUT_SECONDS = 60;

    public static function maxAttempts(): int
    {
        return max(1, (int) SiteSetting::get('login_max_attempts', self::MAX_ATTEMPTS));
    }

    public static function lockoutSeconds(): int
    {
        return max(1, (int) SiteSetting::get('login_lockout_seconds', self::LOCKOUT_SECONDS));
    }
}
