<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Signed, opaque purchase token embedded in every purchase form.
 *
 * The token is an authenticated-encrypted payload (Laravel Crypt), so it is
 * tamper-proof and bound to the issuing user + purchase target — a client can
 * neither forge it, reuse another user's token, nor change the plan after issue.
 * It carries NO price/discount authority. A random nonce makes each issued token
 * unique and serves as the idempotency `key`.
 */
class PurchaseToken
{
    /**
     * Issue a token bound to the user + purchase target.
     *
     * @param  array<string,mixed>  $options  immutable allowed options (e.g. amount_gb)
     */
    public static function issue(int $userId, string $operation, ?int $planId, ?int $serviceId, array $options = []): string
    {
        ksort($options);

        return Crypt::encryptString(json_encode([
            'u' => $userId,
            'op' => $operation,
            'p' => $planId,
            's' => $serviceId,
            'o' => $options,
            'n' => Str::random(40),      // nonce → idempotency key
            'iat' => now()->getTimestamp(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Parse + structurally validate a token. Returns the payload array, or null
     * if the token is missing, tampered, or malformed.
     *
     * @return array{u:int,op:string,p:?int,s:?int,o:array,n:string,iat:int}|null
     */
    public static function parse(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }
        foreach (['u', 'op', 'n', 'iat'] as $required) {
            if (! array_key_exists($required, $decoded)) {
                return null;
            }
        }

        return [
            'u' => (int) $decoded['u'],
            'op' => (string) $decoded['op'],
            'p' => isset($decoded['p']) ? (int) $decoded['p'] : null,
            's' => isset($decoded['s']) ? (int) $decoded['s'] : null,
            'o' => is_array($decoded['o'] ?? null) ? $decoded['o'] : [],
            'n' => (string) $decoded['n'],
            'iat' => (int) $decoded['iat'],
        ];
    }

    /**
     * Stable server-side fingerprint of the immutable purchase parameters. Never
     * includes price, discount, CSRF, timestamps or tracking params.
     *
     * @param  array<string,mixed>  $options
     */
    public static function fingerprint(int $userId, string $operation, ?int $planId, ?int $serviceId, array $options = []): string
    {
        ksort($options);

        return hash('sha256', json_encode([
            'u' => $userId,
            'op' => $operation,
            'p' => $planId,
            's' => $serviceId,
            'o' => $options,
        ], JSON_UNESCAPED_UNICODE));
    }
}
