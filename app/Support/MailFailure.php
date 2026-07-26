<?php

namespace App\Support;

use App\Logging\SensitiveDataProcessor;
use Throwable;

/**
 * Turns a mail/queue Throwable into a SAFE, bounded description for storage
 * (email_verification_codes.send_error) or admin display.
 *
 * Raw exception text from SMTP transports can echo credentials (usernames,
 * DSNs, Authorization headers) — so known failure classes are reduced to a
 * category alone, and anything else is scrubbed through the same sensitive-
 * data masking used by the log pipeline, then truncated.
 */
final class MailFailure
{
    public const MAX_LENGTH = 300;

    /** A short machine-friendly category, e.g. `auth_failed`. */
    public static function categorize(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'authenticat') || str_contains($message, '535') => 'auth_failed',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'timeout',
            str_contains($message, 'connection') || str_contains($message, 'connect')
                || str_contains($message, 'stream_socket') => 'connection_failed',
            str_contains($message, 'ssl') || str_contains($message, 'tls')
                || str_contains($message, 'certificate') => 'tls_failed',
            str_contains($message, 'resolve') || str_contains($message, 'getaddrinfo')
                || str_contains($message, 'dns') => 'dns_failed',
            // ONLY responses proven recipient-specific: a bare 550 can also
            // mean relay denial or a rejected From address — sender-side,
            // site-wide failures that MUST count as outage evidence
            // (transportLooksLive excludes recipient_rejected), so they fall
            // through to `unknown` instead.
            str_contains($message, 'recipient') || str_contains($message, 'mailbox unavailable')
                || str_contains($message, 'user unknown') || str_contains($message, 'no such user')
                || str_contains($message, 'mailbox not found') => 'recipient_rejected',
            default => 'unknown',
        };
    }

    /**
     * Safe stored/displayed form: `<stage>: <category> (<ExceptionClass>) — <scrubbed>`.
     * Never contains raw credentials; bounded to MAX_LENGTH characters.
     */
    public static function summarize(string $stage, Throwable $e): string
    {
        $category = self::categorize($e);

        // Known categories carry enough signal on their own — the raw message
        // adds risk (credential echo) faster than diagnostic value.
        if ($category !== 'unknown') {
            return mb_substr($stage.': '.$category.' ('.class_basename($e).')', 0, self::MAX_LENGTH);
        }

        $scrubbed = SensitiveDataProcessor::scrub($e->getMessage());

        return mb_substr($stage.': '.$category.' ('.class_basename($e).') — '.$scrubbed, 0, self::MAX_LENGTH);
    }
}
