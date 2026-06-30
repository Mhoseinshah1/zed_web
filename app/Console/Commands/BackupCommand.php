<?php

namespace App\Console\Commands;

use App\Models\BackupLog;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Telegram\TelegramAdminNotifier;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'zedproxy:backup
        {--manual : Mark this run as a manual backup (default)}
        {--scheduled : Mark this run as a scheduled backup}
        {--send-to-telegram : Force sending the archive file to Telegram (if within size)}
        {--report-only : Do not run a backup; just send the last backup status}';

    protected $description = 'Run a server backup (PostgreSQL + uploads), with retention and optional Telegram delivery.';

    public function handle(BackupService $service, BackupSettings $settings, TelegramAdminNotifier $notifier): int
    {
        if ($this->option('report-only')) {
            $last = BackupLog::latestLog();
            $text = $last
                ? "💾 آخرین بکاپ: {$last->status} — " . $last->updated_at->format('Y/m/d H:i')
                : '💾 هنوز بکاپی انجام نشده است.';
            $notifier->send('backup_status', 'backup_server', 'وضعیت بکاپ', $text);
            $this->info($text);
            return self::SUCCESS;
        }

        if (! $settings->enabled()) {
            $this->warn('Backup is disabled (backup_enabled is off).');
            return self::SUCCESS;
        }

        $type = $this->option('scheduled') ? BackupLog::TYPE_SCHEDULED : BackupLog::TYPE_MANUAL;
        $force = $this->option('send-to-telegram') ? true : null;

        $result = $service->run($type, $force);

        if ($result['status'] === BackupLog::STATUS_SUCCESS) {
            $this->info('Backup succeeded: ' . ($result['path'] ?? '—') . ' (' . round($result['size'] / 1048576, 2) . ' MB)');
            return self::SUCCESS;
        }

        $this->error('Backup failed: ' . ($result['error'] ?? 'unknown'));
        return self::FAILURE;
    }
}
