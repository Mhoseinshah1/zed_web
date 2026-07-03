<?php

namespace App\Console\Commands;

use App\Models\BackupLog;
use App\Services\Backup\BackupScheduler;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Telegram\TelegramAdminNotifier;
use Illuminate\Console\Command;

/**
 * zedproxy:backup — the single backup entry point.
 *
 * --scheduled runs from the every-minute scheduler; the command itself decides
 * whether a backup is due (mode + time/interval + last run) so admins can
 * change scheduling from the panel without touching cron. Not-due skips are
 * silent (no Telegram spam). --manual ignores auto-scheduling but still
 * requires backup_enabled. Overlap is prevented by a cache lock inside
 * BackupService.
 */
class BackupCommand extends Command
{
    protected $signature = 'zedproxy:backup
        {--manual : Mark this run as a manual backup (default)}
        {--scheduled : Scheduled run — only executes when a backup is due}
        {--send-to-telegram : Force sending the archive file to Telegram (if within size)}
        {--report-only : Do not run a backup; just send the last backup status}';

    protected $description = 'Run a server backup (PostgreSQL + uploads), with retention and optional Telegram delivery.';

    public function handle(BackupService $service, BackupSettings $settings, BackupScheduler $scheduler, TelegramAdminNotifier $notifier): int
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
            $this->warn('سیستم بکاپ در حال حاضر غیرفعال است.');
            return self::SUCCESS;
        }

        if ($this->option('scheduled')) {
            if (! $settings->autoEnabled()) {
                $this->line('Automatic backup is disabled (backup_auto_enabled is off).');
                return self::SUCCESS;
            }
            if (! $scheduler->isDue()) {
                // Not due yet — silent skip (local output only, never Telegram).
                $this->line('Backup not due yet.');
                return self::SUCCESS;
            }
        }

        $type  = $this->option('scheduled') ? BackupLog::TYPE_SCHEDULED : BackupLog::TYPE_MANUAL;
        $force = $this->option('send-to-telegram') ? true : null;

        $result = $service->run($type, $force);

        if ($result['status'] === BackupService::STATUS_SKIPPED_LOCKED) {
            $this->warn('یک عملیات بکاپ دیگر در حال اجرا است.');
            return self::SUCCESS;
        }

        if ($result['status'] === BackupLog::STATUS_SUCCESS) {
            $this->info('Backup succeeded: ' . ($result['path'] ?? '—') . ' (' . round($result['size'] / 1048576, 2) . ' MB)');
            return self::SUCCESS;
        }

        $this->error('Backup failed: ' . ($result['error'] ?? 'unknown'));
        return self::FAILURE;
    }
}
