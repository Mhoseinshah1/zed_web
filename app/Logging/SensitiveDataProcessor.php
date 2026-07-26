<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that masks credentials and secrets before a log record is
 * written. It scrubs both the structured context/extra arrays (by key name)
 * and the free-text message (by pattern), so that admin passwords, DB
 * passwords, APP_KEY, API/Telegram/payment/VPN tokens and Authorization
 * headers never reach any log file, syslog, or off-box log sink.
 *
 * Non-sensitive context (ids, status, urls without embedded credentials,
 * counts, timings) is intentionally preserved.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    /**
     * Context/extra keys whose values are always masked (case-insensitive,
     * matched loosely so `db_password`, `X-Api-Token`, `access_token` etc. are
     * all covered).
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'pass',
        'passwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'api_token',
        'apikey',
        'apitoken',
        'client_secret',
        'app_key',
        'appkey',
        'db_password',
        'dbpassword',
        'pgpassword',
        'authorization',
        'auth',
        'cookie',
        'set-cookie',
        'signature',
        'private_key',
        'privatekey',
        'webhook_secret',
        'bot_token',
        'telegram_token',
    ];

    /**
     * Scrub a free-text string outside the logging pipeline (e.g. before
     * persisting a delivery error to the database). Same pattern set as the
     * log processor — credentials, tokens, DSNs and Authorization headers are
     * masked.
     */
    public static function scrub(string $value): string
    {
        return (new self)->maskString($value);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->maskString($record->message);
        $context = $this->maskArray($record->context);
        $extra = $this->maskArray($record->extra);

        return $record->with(
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    /**
     * Recursively mask an array by key name, and scrub any leftover secrets in
     * string leaves by pattern.
     */
    private function maskArray(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->maskArray($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->maskString($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $needle) {
            $needleNormalized = str_replace('-', '_', $needle);
            if ($normalized === $needleNormalized || str_contains($normalized, $needleNormalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pattern-based scrubbing for free text (messages and string leaves).
     */
    private function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $patterns = [
            // KEY=VALUE style assignments (APP_KEY=, DB_PASSWORD=, TOKEN=, ...).
            '/([A-Za-z0-9_]*(?:PASSWORD|PASSWD|PASS|SECRET|TOKEN|APP_KEY|API[_-]?KEY|PGPASSWORD|USERNAME|USER|LOGIN))[[:space:]]*=[[:space:]]*[^[:space:]"\'\\\\]+/i' => '$1='.self::REDACTED,
            // Authorization: Bearer / Basic <token>.
            '/(Authorization[[:space:]]*:[[:space:]]*)(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+\/=-]+/i' => '$1$2 '.self::REDACTED,
            // user:pass@host inside URLs.
            '#([A-Za-z][A-Za-z0-9+.-]*://)[^/@\s:]+:[^/@\s]+@#' => '$1'.self::REDACTED.'@',
            // JSON "field": "value" pairs for credential-ish keys.
            '/("?(?:password|passwd|secret|token|api[_-]?key|api[_-]?token|access[_-]?token|refresh[_-]?token|authorization|signature)"?[[:space:]]*:[[:space:]]*")[^"]*"/i' => '$1'.self::REDACTED.'"',
            // Quoted credentials in prose or assignments — `password "y"`,
            // `API_KEY='sk-…'`, `token="…"`, `client_secret='…'`: the
            // KEY=VALUE pattern above deliberately skips quoted values, so
            // every credential-key variant must be covered here too.
            '/\b(username|user|login|password|passwd|pwd|secret|token|api[_-]?key|api[_-]?token|apikey|client[_-]?secret|access[_-]?token|refresh[_-]?token|app[_-]?key)\b[[:space:]]*[:=]?[[:space:]]*["\'][^"\']*["\']/i' => '$1 '.self::REDACTED,
            // UNQUOTED colon-delimited credentials in prose or headers, e.g.
            // `password: hunter2`, `api_key: sk-abc123`, and SMTP logins like
            // `username: mail-user@example.com` — exception text from unknown
            // transports formats secrets this way too, and the quoted pattern
            // above already treats usernames as credentials.
            '/\b(username|user|login|password|passwd|pwd|secret|token|api[_-]?key|api[_-]?token|apikey|client[_-]?secret|access[_-]?token|refresh[_-]?token)\b[[:space:]]*:[[:space:]]*[^[:space:]"\'][^[:space:]]*/i' => '$1: '.self::REDACTED,
            // GitHub tokens.
            '/\b(?:gh[posur]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})\b/' => self::REDACTED,
            // Telegram bot token: <digits>:<35+ chars>.
            '/\b[0-9]{6,}:[A-Za-z0-9_-]{30,}\b/' => self::REDACTED,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
