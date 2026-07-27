<?php

namespace App\Services\Backup;

/**
 * THE single authoritative policy for the configured backup storage path.
 * Filament validation, save-time normalization and runtime execution all go
 * through this class, so a value that bypassed the admin form (edited
 * directly in the database, or saved before validation existed) is judged by
 * exactly the same rules at run time — it can never silently resolve against
 * the process CWD.
 *
 * Validation order (deliberate): the RAW value is inspected for NUL and
 * control characters FIRST, before any trimming or normalization — trimming
 * must never turn an invalid value into a valid path. The only tolerated
 * decoration is ordinary leading/trailing SPACE characters (0x20), which are
 * stripped after that check; this is the complete, documented trimming
 * policy.
 *
 * Policy:
 *  - empty value (or spaces only) → the application default (storage/app/backups)
 *  - NUL / control chars anywhere → REJECTED (checked on the raw value)
 *  - relative value               → REJECTED (fail closed; never auto-prefixed)
 *  - "." / ".." segments          → REJECTED (no traversal ambiguity)
 *  - filesystem root "/"          → REJECTED
 *  - valid absolute value         → normalized (collapsed slashes, no trailing "/")
 *
 * Symlink policy: the configured root may be or contain symlinks (valid for
 * existing installations). BackupService canonicalizes the verified root
 * once via realpath() at the start of a run and uses that single pinned
 * path for the whole run.
 *
 * Recovery for a stored invalid value (e.g. a legacy relative path): open
 * «بکاپ و سرور» in the admin panel and save an absolute path (or clear the
 * field to use the default) — nothing else is required.
 */
class BackupPathPolicy
{
    /** The default backup root used when no path is configured. */
    public function defaultPath(): string
    {
        return storage_path('app/backups');
    }

    /**
     * Resolve a stored setting value to a usable absolute backup root.
     * Empty (after the documented space-only trim) means "use the default".
     *
     * @throws BackupFailure config-category failure for an invalid value
     */
    public function resolve(?string $raw): string
    {
        $raw = (string) $raw;
        $this->rejectControlCharacters($raw);

        $p = trim($raw, ' ');

        return $p === '' ? $this->defaultPath() : $this->validateAbsolute($p);
    }

    /**
     * Normalize an admin-submitted value for storage: '' (use default) or
     * the validated normalized absolute path. Same raw-first order as
     * resolve() — used by the settings page so the stored value can never
     * differ from what the runtime would accept.
     *
     * @throws BackupFailure
     */
    public function normalizeForStorage(?string $raw): string
    {
        $raw = (string) $raw;
        $this->rejectControlCharacters($raw);

        $p = trim($raw, ' ');

        return $p === '' ? '' : $this->validateAbsolute($p);
    }

    /**
     * Validate + normalize an explicitly configured path. Returns the
     * normalized absolute path or throws a sanitized config failure — the
     * offending value is never echoed into the message, and no absolute
     * server path is disclosed either.
     *
     * @throws BackupFailure
     */
    public function validateAbsolute(string $path): string
    {
        $this->rejectControlCharacters($path);

        if (! str_starts_with($path, '/')) {
            throw BackupFailure::config(
                'مسیر ذخیره بکاپ باید یک مسیر مطلق باشد (با / شروع شود)؛ مسیر نسبی پذیرفته نمی‌شود. '
                .'برای استفاده از مسیر پیش‌فرض برنامه (storage/app/backups)، فیلد را خالی بگذارید.',
                'not_absolute',
            );
        }

        $normalized = rtrim((string) preg_replace('#/{2,}#', '/', $path), '/');

        if ($normalized === '') {
            throw BackupFailure::config(
                'ریشه فایل‌سیستم (/) به‌عنوان مسیر بکاپ مجاز نیست. یک زیرمسیر مطلق انتخاب کنید.',
                'filesystem_root',
            );
        }

        foreach (explode('/', ltrim($normalized, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw BackupFailure::config(
                    'مسیر ذخیره بکاپ نباید شامل بخش‌های «.» یا «..» باشد. یک مسیر مطلق صریح وارد کنید.',
                    'dot_segments',
                );
            }
        }

        return $normalized;
    }

    /**
     * Make sure the validated backup root is actually usable: create it
     * recursively when missing (absolute path — never CWD-relative), then
     * verify it exists, is a directory, and is writable. Every filesystem
     * outcome is checked deterministically (no suppressed operations).
     *
     * @throws BackupFailure permission/config-category failure
     */
    public function ensureUsableRoot(string $root): void
    {
        if (! is_dir($root)) {
            if (file_exists($root)) {
                throw BackupFailure::config(
                    'مسیر ذخیره بکاپ به یک فایل اشاره می‌کند، نه یک پوشه. مسیر دیگری انتخاب کنید.',
                    'root_not_directory',
                );
            }

            // is_dir() re-check keeps this race-safe against a concurrent creator.
            if (! CheckedFilesystem::mkdir($root, 0750, true) && ! is_dir($root)) {
                throw BackupFailure::permission(
                    'امکان ساخت پوشه ذخیره بکاپ وجود ندارد. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                    'root_mkdir_failed',
                );
            }
        }

        if (! is_writable($root)) {
            throw BackupFailure::permission(
                'پوشه ذخیره بکاپ قابل نوشتن نیست. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                'root_not_writable',
            );
        }
    }

    /**
     * Reject NUL and every prohibited control character, at ANY position of
     * the RAW value — before trimming can strip or hide them.
     *
     * @throws BackupFailure
     */
    private function rejectControlCharacters(string $raw): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw BackupFailure::config(
                'مسیر ذخیره بکاپ شامل کاراکترهای غیرمجاز است. یک مسیر مطلق معتبر وارد کنید.',
                'control_characters',
            );
        }
    }
}
