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
    public function enabled(): bool        { return (bool) SiteSetting::get('backup_enabled', false); }
    public function autoEnabled(): bool    { return (bool) SiteSetting::get('backup_auto_enabled', false); }

    /** "HH:MM" 24h schedule time. */
    public function scheduleTime(): string
    {
        $t = (string) SiteSetting::get('backup_schedule_time', '03:00');
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '03:00';
    }

    public function retentionDays(): int
    {
        return max(1, (int) SiteSetting::get('backup_retention_days', 7));
    }

    public function storagePath(): string
    {
        $p = trim((string) SiteSetting::get('backup_storage_path', ''));
        return $p !== '' ? $p : storage_path('app/backups');
    }

    public function includeDatabase(): bool     { return (bool) SiteSetting::get('backup_include_database', true); }
    public function includeStorage(): bool      { return (bool) SiteSetting::get('backup_include_storage', true); }
    public function includeUploads(): bool      { return (bool) SiteSetting::get('backup_include_uploads', true); }
    public function includeProjectFiles(): bool { return (bool) SiteSetting::get('backup_include_project_files', false); }
    public function excludeSensitive(): bool    { return (bool) SiteSetting::get('backup_exclude_sensitive_files', true); }

    public function encryptEnabled(): bool { return (bool) SiteSetting::get('backup_encrypt_enabled', false); }

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

    public function hasPassword(): bool { return $this->password() !== ''; }

    public function storePassword(string $password): void
    {
        $password = trim($password);
        if ($password === '') {
            return;
        }
        SiteSetting::set('backup_password', Crypt::encryptString($password));
    }

    public function sendFileToTelegram(): bool   { return (bool) SiteSetting::get('backup_send_file_to_telegram', false); }
    public function sendReportToTelegram(): bool { return (bool) SiteSetting::get('backup_send_report_to_telegram', true); }

    public function maxTelegramFileMb(): int
    {
        // Telegram bots can upload up to ~50MB.
        return max(1, min(50, (int) SiteSetting::get('backup_max_telegram_file_size_mb', 50)));
    }

    public function topicKey(): string { return 'backup_server'; }
}
