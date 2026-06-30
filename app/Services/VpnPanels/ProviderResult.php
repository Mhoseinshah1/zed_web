<?php

namespace App\Services\VpnPanels;

/**
 * Lightweight result object returned by every VpnPanelProvider method, so
 * business logic can branch on success/failure without catching provider-
 * specific exceptions. Messages are user-safe Persian strings.
 */
class ProviderResult
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public bool $ok,
        public string $message = '',
        public array $data = [],
        public bool $unsupported = false,
    ) {}

    /** @param array<string,mixed> $data */
    public static function success(string $message = '', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    /** A capability the panel type does not support — never an error/crash. */
    public static function unsupported(string $message = 'این قابلیت برای این نوع پنل پشتیبانی نمی‌شود.'): self
    {
        return new self(false, $message, [], true);
    }
}
