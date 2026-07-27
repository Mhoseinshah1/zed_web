<?php

namespace App\Services\Backup;

/**
 * Checked filesystem mutations for the backup lifecycle.
 *
 * Every operation returns a deterministic boolean instead of relying on
 * `@`-suppressed calls: the PHP warning a failing mutation would emit is
 * captured by a handler scoped to that single call (nothing is hidden
 * globally, and no other code's warnings are affected), and the caller
 * decides — based on its own criticality — whether a `false` result is a
 * hard failure, a security failure, or non-critical housekeeping.
 *
 * The captured warning TEXT is intentionally discarded: it contains
 * absolute paths, and the backup logging policy only records positive-listed
 * safe fields (category, stage, reason code, exit class).
 */
final class CheckedFilesystem
{
    /** @param callable():bool $op */
    private static function guarded(callable $op): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return (bool) $op();
        } finally {
            restore_error_handler();
        }
    }

    public static function mkdir(string $path, int $mode, bool $recursive = false): bool
    {
        return self::guarded(static fn (): bool => mkdir($path, $mode, $recursive));
    }

    public static function rename(string $from, string $to): bool
    {
        return self::guarded(static fn (): bool => rename($from, $to));
    }

    public static function unlink(string $path): bool
    {
        return self::guarded(static fn (): bool => unlink($path));
    }

    public static function rmdir(string $path): bool
    {
        return self::guarded(static fn (): bool => rmdir($path));
    }
}
