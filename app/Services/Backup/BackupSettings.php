<?php

namespace App\Services\Backup;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed accessors for all backup settings (stored in SiteSetting — no .env).
 * The optional archive password is stored ENCRYPTED and never shown.
 */
class BackupSettings
{
    public function enabled(): bool
    {
        return (bool) SiteSetting::get('backup_enabled', false);
    }

    public function autoEnabled(): bool
    {
        return (bool) SiteSetting::get('backup_auto_enabled', false);
    }

    public const MODE_FIXED_TIME = 'fixed_time';

    public const MODE_INTERVAL = 'interval';

    /** Scheduling mode: fixed_time (daily at HH:MM) or interval (every N minutes). */
    public function scheduleMode(): string
    {
        $mode = (string) SiteSetting::get('backup_schedule_mode', self::MODE_FIXED_TIME);

        return $mode === self::MODE_INTERVAL ? self::MODE_INTERVAL : self::MODE_FIXED_TIME;
    }

    /** "HH:MM" 24h schedule time. */
    public function scheduleTime(): string
    {
        $t = (string) SiteSetting::get('backup_schedule_time', '03:00');

        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '03:00';
    }

    /** Minutes between interval-mode backups, clamped to the configured minimum. */
    public function intervalMinutes(): int
    {
        return max($this->minIntervalMinutes(), (int) SiteSetting::get('backup_interval_minutes', 60));
    }

    /** Safety floor for the interval (configurable; default 5 minutes). */
    public function minIntervalMinutes(): int
    {
        return max(1, (int) SiteSetting::get('backup_min_interval_minutes', 5));
    }

    public function retentionDays(): int
    {
        return max(1, (int) SiteSetting::get('backup_retention_days', 7));
    }

    /**
     * The validated absolute backup root. Goes through BackupPathPolicy, so a
     * legacy/invalid stored value (relative path, control chars, traversal)
     * fails closed here — even when it bypassed the admin form and was
     * written straight into the database.
     *
     * @throws BackupFailure config-category failure for an invalid stored value
     */
    public function storagePath(): string
    {
        return app(BackupPathPolicy::class)->resolve(
            (string) SiteSetting::get('backup_storage_path', ''),
        );
    }

    /**
     * Non-sensitive LOGICAL description of where backups are stored, for
     * operator-facing channels (Telegram {path} placeholder). Never the real
     * filesystem path.
     */
    public function storageLocationLabel(): string
    {
        $raw = trim((string) SiteSetting::get('backup_storage_path', ''), ' ');

        return $raw === ''
            ? 'مسیر پیش‌فرض برنامه (storage/app/backups)'
            : 'مسیر سفارشی تنظیم‌شده در پنل';
    }

    public function includeDatabase(): bool
    {
        return (bool) SiteSetting::get('backup_include_database', true);
    }

    public function includeStorage(): bool
    {
        return (bool) SiteSetting::get('backup_include_storage', true);
    }

    public function includeUploads(): bool
    {
        return (bool) SiteSetting::get('backup_include_uploads', true);
    }

    public function includeProjectFiles(): bool
    {
        return (bool) SiteSetting::get('backup_include_project_files', false);
    }

    public function excludeSensitive(): bool
    {
        return (bool) SiteSetting::get('backup_exclude_sensitive_files', true);
    }

    public function encryptEnabled(): bool
    {
        return (bool) SiteSetting::get('backup_encrypt_enabled', false);
    }

    /** Password states: usable / never stored / stored but unreadable. */
    public const PASSWORD_OK = 'ok';

    public const PASSWORD_NONE = 'none';

    public const PASSWORD_INVALID = 'invalid';

    /**
     * Distinguish "no password was ever stored" from "a password is stored
     * but cannot be used" (corrupt ciphertext, or encrypted under a
     * different APP_KEY) — WITHOUT exposing ciphertext, keys, or decrypt
     * exception detail. Fail-closed encryption relies on this: enabled
     * encryption with anything other than PASSWORD_OK must never fall back
     * to a plaintext backup.
     */
    public function passwordState(): string
    {
        $raw = (string) SiteSetting::get('backup_password', '');
        if ($raw === '') {
            return self::PASSWORD_NONE;
        }
        try {
            return (string) Crypt::decryptString($raw) !== '' ? self::PASSWORD_OK : self::PASSWORD_INVALID;
        } catch (\Throwable) {
            return self::PASSWORD_INVALID;
        }
    }

    /** Decrypted archive password, or '' if unset/undecryptable. Never shown. */
    public function password(): string
    {
        $raw = (string) SiteSetting::get('backup_password', '');
        if ($raw === '') {
            return '';
        }
        try {
            return (string) Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function hasPassword(): bool
    {
        return $this->password() !== '';
    }

    public function storePassword(string $password): void
    {
        $password = trim($password);
        if ($password === '') {
            return;
        }
        SiteSetting::set('backup_password', Crypt::encryptString($password));
    }

    public function sendFileToTelegram(): bool
    {
        return (bool) SiteSetting::get('backup_send_file_to_telegram', false);
    }

    public function sendReportToTelegram(): bool
    {
        return (bool) SiteSetting::get('backup_send_report_to_telegram', true);
    }

    public function maxTelegramFileMb(): int
    {
        // Telegram bots can upload up to ~50MB.
        return max(1, min(50, (int) SiteSetting::get('backup_max_telegram_file_size_mb', 50)));
    }

    public function topicKey(): string
    {
        return 'backup_server';
    }
}
