<?php

namespace App\Services\AdminMfa;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sanitized security-event trail for administrator MFA and sensitive-settings
 * step-up. The repository has no generic audit table, so events go through the
 * application log pipeline (which already runs the sensitive-data masking
 * processor). Context is built HERE from an explicit positive list — never
 * from request bags or exceptions — so a password, TOTP code/secret, recovery
 * code, or communication credential can never reach an event by construction.
 */
final class AdminSecurityAudit
{
    /**
     * @param  string  $event  machine category, e.g. `mfa_challenge`
     * @param  string  $result  `success` | `failure` | `expired` | ...
     */
    public static function record(string $event, ?User $user, string $result, array $context = []): void
    {
        $request = request();

        Log::info('admin-security: '.$event, array_merge([
            'user_id' => $user?->id,
            'result' => $result,
            'ip' => self::maskIp($request?->ip()),
            'user_agent' => self::maskUserAgent($request?->userAgent()),
        ], self::safeContext($context)));
    }

    /** Keep only whitelisted, non-secret extra keys. */
    private static function safeContext(array $context): array
    {
        $allowed = ['reason', 'scope', 'via', 'remaining_recovery_codes', 'target_user_id', 'command'];

        return array_intersect_key($context, array_flip($allowed));
    }

    /** IPv4 → a.b.*.*; IPv6 → first two hextets. Never the full address. */
    private static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 2)).'::*';
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? $parts[0].'.'.$parts[1].'.*.*' : '*';
    }

    /** Coarse metadata only — never the raw UA string. */
    private static function maskUserAgent(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        return mb_substr(preg_replace('/[^\w\s\/.;()-]/u', '', $ua) ?? '', 0, 80);
    }
}
