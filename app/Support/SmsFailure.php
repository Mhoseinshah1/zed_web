<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Turns an SMS-provider Throwable into a SAFE, bounded description — the SMS
 * counterpart of MailFailure. Raw provider/HTTP exception text can echo API
 * keys, Authorization headers, credentialed URLs, or request bodies, so known
 * classes reduce to a category and anything else is scrubbed through the
 * shared secret masking, then truncated. Adapters "intend" to keep
 * credentials out of messages — that intent is never assumed here.
 */
final class SmsFailure
{
    public const MAX_LENGTH = 300;

    /** A short machine-friendly category, e.g. `http_error`. */
    public static function categorize(Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return 'connection_failed';
        }

        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'could not resolve'), str_contains($message, 'dns') => 'dns_error',
            str_contains($message, 'ssl'), str_contains($message, 'certificate') => 'tls_error',
            str_contains($message, '401'), str_contains($message, 'unauthorized'), str_contains($message, 'authentication') => 'auth_failed',
            str_contains($message, '403'), str_contains($message, 'forbidden') => 'access_denied',
            str_contains($message, '429'), str_contains($message, 'too many') => 'rate_limited',
            preg_match('/\b5\d{2}\b/', $message) === 1 => 'provider_error',
            preg_match('/\b4\d{2}\b/', $message) === 1 => 'http_error',
            default => 'send_failed',
        };
    }

    /** Bounded, masked description safe for logs (never for display). */
    public static function summarize(string $context, Throwable $e): string
    {
        $summary = $context.': '.self::categorize($e).' — '.SecretMasker::mask($e->getMessage());

        return mb_substr($summary, 0, self::MAX_LENGTH);
    }
}
