<?php

namespace App\Services\Backup;

/**
 * THE single authoritative policy for the configured backup storage path.
 * Filament validation and runtime execution both go through this class, so a
 * value that bypassed the admin form (edited directly in the database, or
 * saved before validation existed) is judged by exactly the same rules at
 * run time — it can never silently resolve against the process CWD.
 *
 * Policy:
 *  - empty value            → the application default (storage/app/backups)
 *  - relative value         → REJECTED (fail closed; never auto-prefixed)
 *  - NUL / control chars    → REJECTED
 *  - "." / ".." segments    → REJECTED (no traversal ambiguity)
 *  - filesystem root "/"    → REJECTED
 *  - valid absolute value   → normalized (collapsed slashes, no trailing "/")
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
     * Empty means "use the default"; anything else must pass validateAbsolute().
     *
     * @throws BackupFailure config-category failure for an invalid value
     */
    public function resolve(?string $raw): string
    {
        $p = trim((string) $raw);

        return $p === '' ? $this->defaultPath() : $this->validateAbsolute($p);
    }

    /**
     * Validate + normalize an explicitly configured path. Returns the
     * normalized absolute path or throws a sanitized config failure — the
     * offending value itself is never echoed into the message.
     *
     * @throws BackupFailure
     */
    public function validateAbsolute(string $path): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw BackupFailure::config(
                'مسیر ذخیره بکاپ شامل کاراکترهای غیرمجاز است. یک مسیر مطلق معتبر وارد کنید.',
                'backup storage path contains NUL/control characters',
            );
        }

        if (! str_starts_with($path, '/')) {
            throw BackupFailure::config(
                'مسیر ذخیره بکاپ باید یک مسیر مطلق باشد (با / شروع شود)، مثلاً '.$this->defaultPath()
                .' — مسیر نسبی پذیرفته نمی‌شود. برای استفاده از مسیر پیش‌فرض، فیلد را خالی بگذارید.',
                'backup storage path is not absolute',
            );
        }

        $normalized = rtrim((string) preg_replace('#/{2,}#', '/', $path), '/');

        if ($normalized === '') {
            throw BackupFailure::config(
                'ریشه فایل‌سیستم (/) به‌عنوان مسیر بکاپ مجاز نیست. یک زیرمسیر مطلق انتخاب کنید.',
                'backup storage path is the filesystem root',
            );
        }

        foreach (explode('/', ltrim($normalized, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw BackupFailure::config(
                    'مسیر ذخیره بکاپ نباید شامل بخش‌های «.» یا «..» باشد. یک مسیر مطلق صریح وارد کنید.',
                    'backup storage path contains dot/dot-dot segments',
                );
            }
        }

        return $normalized;
    }

    /**
     * Make sure the validated backup root is actually usable: create it
     * recursively when missing (absolute path — never CWD-relative), then
     * verify it exists, is a directory, and is writable. No suppressed,
     * unchecked filesystem calls: every outcome is verified.
     *
     * @throws BackupFailure permission/config-category failure
     */
    public function ensureUsableRoot(string $root): void
    {
        if (! is_dir($root)) {
            if (file_exists($root)) {
                throw BackupFailure::config(
                    'مسیر ذخیره بکاپ به یک فایل اشاره می‌کند، نه یک پوشه. مسیر دیگری انتخاب کنید.',
                    'backup storage path exists but is not a directory: '.$root,
                );
            }

            // @ only silences the duplicate PHP warning; the result is checked
            // (with an is_dir() re-check to stay race-safe against a
            // concurrent creator) and failure becomes a hard error.
            $created = @mkdir($root, 0750, true);
            if (! $created && ! is_dir($root)) {
                $err = error_get_last()['message'] ?? 'mkdir failed';

                throw BackupFailure::permission(
                    'امکان ساخت پوشه ذخیره بکاپ وجود ندارد. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                    'mkdir failed for backup root '.$root.': '.$err,
                );
            }
        }

        if (! is_writable($root)) {
            throw BackupFailure::permission(
                'پوشه ذخیره بکاپ قابل نوشتن نیست. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                'backup root is not writable: '.$root,
            );
        }
    }
}
