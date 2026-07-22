<?php

namespace App\Support;

/**
 * Redacts sensitive values (credentials, hostnames, endpoints) from free-text
 * strings before they reach a log file or an admin diagnostics screen.
 *
 * The public /health endpoint never emits any of this text at all; masking is a
 * defence-in-depth layer for the internal diagnostics and the log stream.
 */
class SecretMasker
{
    private const REDACTED = '***';

    /**
     * Mask secrets in the given text. Safe to call on null.
     */
    public static function mask(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // 1) Replace exact known secret values pulled from configuration.
        foreach (self::sensitiveValues() as $value) {
            if ($value !== '' && mb_strlen($value) >= 3) {
                $text = str_ireplace($value, self::REDACTED, $text);
            }
        }

        // 2) Redact credentials embedded in connection URLs (scheme://user:pass@host).
        $text = preg_replace('#([a-z][a-z0-9+.\-]*://)[^/@\s:]+:[^/@\s]+@#i', '$1' . self::REDACTED . ':' . self::REDACTED . '@', $text) ?? $text;

        // 3) Redact bare IPv4[:port] and host:port endpoints.
        $text = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}(?::\d+)?\b/', self::REDACTED, $text) ?? $text;
        $text = preg_replace('/\b[a-z0-9.\-]+\.[a-z]{2,}(?::\d+)?\b/i', self::REDACTED, $text) ?? $text;

        // 4) Redact common key=value credential pairs.
        $text = preg_replace('/\b(password|passwd|pass|pwd|secret|token|api[_-]?key|auth)\b\s*[=:]\s*\S+/i', '$1=' . self::REDACTED, $text) ?? $text;

        // 5) Collapse a trailing :port left over after a host/IP was redacted in
        // an earlier step (e.g. "***:6379").
        $text = preg_replace('/' . preg_quote(self::REDACTED, '/') . '(?::\d{2,5})+\b/', self::REDACTED, $text) ?? $text;

        return $text;
    }

    /**
     * Sensitive concrete values gathered from configuration.
     *
     * @return array<int, string>
     */
    private static function sensitiveValues(): array
    {
        $keys = [
            'database.connections.pgsql.password',
            'database.connections.pgsql.username',
            'database.connections.pgsql.host',
            'database.connections.mysql.password',
            'database.connections.mysql.username',
            'database.connections.mysql.host',
            'database.redis.default.password',
            'database.redis.default.host',
            'database.redis.default.url',
            'database.redis.cache.password',
            'database.redis.cache.host',
            'cache.stores.redis.connection',
            'app.key',
            'mail.mailers.smtp.password',
            'mail.mailers.smtp.username',
        ];

        $values = [];
        foreach ($keys as $key) {
            $value = config($key);
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        // Longest first so a host that is a substring of a URL is masked first.
        usort($values, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($values));
    }
}
