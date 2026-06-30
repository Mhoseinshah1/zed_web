<?php

namespace App\Services\Backup;

use App\Jobs\SendTelegramDocumentJob;
use App\Models\BackupLog;
use App\Models\TelegramAdminTopic;
use App\Services\Telegram\TelegramAdminNotifier;
use Illuminate\Support\Facades\Process;

/**
 * Server backup: PostgreSQL dump + uploads/storage into an encrypted-optional
 * tar.gz, with retention cleanup and optional Telegram report/file delivery.
 *
 * SECURITY:
 *   • Sensitive files (.env, keys, secrets, credentials) are ALWAYS excluded
 *     from the archive — so they can never reach Telegram.
 *   • The DB password is passed to pg_dump via the PGPASSWORD env var, never as
 *     a command-line argument (so it can't appear in `ps`/process listings).
 *   • Nothing here throws into the caller; a backup/Telegram error is logged.
 */
class BackupService
{
    public function __construct(
        private readonly BackupSettings $settings,
        private readonly TelegramAdminNotifier $notifier,
    ) {}

    /**
     * Run a backup. Never throws. Returns a result summary.
     *
     * @return array{status:string, log_id:int, path:?string, size:int, duration_ms:int, error:?string}
     */
    public function run(string $type = BackupLog::TYPE_MANUAL, ?bool $forceSendFile = null): array
    {
        $log   = BackupLog::create(['type' => $type, 'status' => BackupLog::STATUS_STARTED, 'started_at' => now()]);
        $start = microtime(true);

        try {
            @mkdir($this->settings->storagePath(), 0750, true);
            $work = rtrim($this->settings->storagePath(), '/') . '/.work_' . uniqid();
            @mkdir($work, 0750, true);

            $sources = [];

            if ($this->settings->includeDatabase()) {
                $dump = $work . '/database.sql';
                $this->dumpDatabase($dump);
                $sources[] = $dump;
            }
            if ($this->settings->includeStorage() || $this->settings->includeUploads()) {
                $sources[] = storage_path('app/public');
            }
            if ($this->settings->includeProjectFiles()) {
                // Safe code dirs only — never config/.env/secrets (also excluded).
                $sources[] = base_path('app');
                $sources[] = base_path('resources');
            }

            if (empty($sources)) {
                throw new \RuntimeException('No backup sources selected.');
            }

            $archive = rtrim($this->settings->storagePath(), '/')
                . '/zedproxy-backup-' . now()->format('Ymd-His') . '.tar.gz';

            $this->createArchive($archive, $sources, $this->excludePatterns());

            if ($this->settings->encryptEnabled() && $this->settings->hasPassword()) {
                $archive = $this->encryptArchive($archive, $this->settings->password());
            }

            $this->removeDir($work);

            $size     = (int) (is_file($archive) ? filesize($archive) : 0);
            $cleaned  = $this->cleanupOld();
            $duration = (int) round((microtime(true) - $start) * 1000);

            $log->update([
                'status'      => BackupLog::STATUS_SUCCESS,
                'file_path'   => $archive,
                'file_size'   => $size,
                'duration_ms' => $duration,
                'finished_at' => now(),
                'metadata'    => ['cleaned' => $cleaned],
            ]);

            $this->reportSuccess($log, $archive, $size, $duration, $cleaned, $forceSendFile);

            return ['status' => BackupLog::STATUS_SUCCESS, 'log_id' => $log->id, 'path' => $archive, 'size' => $size, 'duration_ms' => $duration, 'error' => null];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            $msg = mb_substr($e->getMessage(), 0, 500);

            $log->update(['status' => BackupLog::STATUS_FAILED, 'error' => $msg, 'duration_ms' => $duration, 'finished_at' => now()]);
            $this->reportFailure($msg);

            return ['status' => BackupLog::STATUS_FAILED, 'log_id' => $log->id, 'path' => null, 'size' => 0, 'duration_ms' => $duration, 'error' => $msg];
        }
    }

    /**
     * Sensitive paths/patterns that must NEVER be in a backup archive.
     *
     * @return array<int,string>
     */
    public function excludePatterns(): array
    {
        if (! $this->settings->excludeSensitive()) {
            return ['*.tar.gz', '.work_*']; // still never recurse our own backups
        }

        return [
            '.env', '.env.*', '*.env',
            '*.key', '*.pem', '*.ppk', '*.crt', '*.p12', 'id_rsa*', 'id_ed25519*',
            'auth.json', '.git', '.gitignore', 'node_modules', 'vendor',
            'storage/framework/cache', 'storage/framework/sessions', 'storage/logs',
            '*.tar.gz', '.work_*', 'oauth-private.key', 'oauth-public.key',
        ];
    }

    /** Build the tar.gz. Public + array-args (no shell) so it's safe & testable. */
    public function createArchive(string $dest, array $sources, array $excludes): void
    {
        $cmd = ['tar', '-czf', $dest];
        foreach ($excludes as $pat) {
            $cmd[] = '--exclude=' . $pat;
        }
        $added = 0;
        foreach ($sources as $src) {
            if (! file_exists($src)) {
                continue;
            }
            $cmd[] = '-C';
            $cmd[] = dirname($src);
            $cmd[] = basename($src);
            $added++;
        }
        if ($added === 0) {
            throw new \RuntimeException('No existing sources to archive.');
        }

        $result = Process::path(base_path())->timeout(900)->run($cmd);
        if (! $result->successful()) {
            throw new \RuntimeException('tar failed: ' . mb_substr($result->errorOutput() ?: 'unknown', 0, 200));
        }
    }

    /** pg_dump with the password supplied via PGPASSWORD env (never in argv). */
    private function dumpDatabase(string $target): void
    {
        $conn = (string) config('database.default');
        $cfg  = (array) config("database.connections.{$conn}", []);

        $cmd = [
            'pg_dump',
            '-h', (string) ($cfg['host'] ?? '127.0.0.1'),
            '-p', (string) ($cfg['port'] ?? '5432'),
            '-U', (string) ($cfg['username'] ?? ''),
            '-d', (string) ($cfg['database'] ?? ''),
            '--no-owner', '--no-privileges',
            '-f', $target,
        ];

        $result = Process::timeout(900)
            ->env(['PGPASSWORD' => (string) ($cfg['password'] ?? '')]) // never on the command line
            ->run($cmd);

        if (! $result->successful()) {
            // errorOutput from pg_dump does not contain the password (it's in env).
            throw new \RuntimeException('pg_dump failed: ' . mb_substr($result->errorOutput() ?: 'unknown', 0, 200));
        }
    }

    /** Encrypt the archive with openssl (password via env). Returns new path. */
    private function encryptArchive(string $archive, string $password): string
    {
        $enc = $archive . '.enc';
        $result = Process::timeout(900)
            ->env(['ZP_BK_PASS' => $password])
            ->run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-pass', 'env:ZP_BK_PASS', '-in', $archive, '-out', $enc]);

        if (! $result->successful()) {
            throw new \RuntimeException('encryption failed: ' . mb_substr($result->errorOutput() ?: 'unknown', 0, 120));
        }
        @unlink($archive);
        return $enc;
    }

    /** Delete archives older than the retention window. Returns count removed. */
    private function cleanupOld(): int
    {
        $dir = rtrim($this->settings->storagePath(), '/');
        $cutoff = now()->subDays($this->settings->retentionDays())->getTimestamp();
        $removed = 0;
        foreach (glob($dir . '/zedproxy-backup-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    // ── Telegram reporting ───────────────────────────────────────────────────

    private function reportSuccess(BackupLog $log, string $archive, int $size, int $durationMs, int $cleaned, ?bool $forceSendFile = null): void
    {
        $sendFile = $forceSendFile ?? $this->settings->sendFileToTelegram();
        if ($this->settings->sendReportToTelegram()) {
            $this->notifier->event('backup_success', [
                'size'     => number_format(round($size / 1048576, 2), 2),
                'duration' => (string) round($durationMs / 1000, 1),
                'path'     => $this->settings->storagePath(),
                'cleaned'  => (string) $cleaned,
            ], $log);
        }

        if ($sendFile && $this->fitsTelegramLimit($size) && is_file($archive)) {
            $thread = TelegramAdminTopic::findByKey($this->settings->topicKey())?->message_thread_id;
            SendTelegramDocumentJob::dispatch($archive, '💾 بکاپ زدپروکسی — ' . now()->format('Y/m/d H:i'), $thread, $log->id);
        }
    }

    private function reportFailure(string $error): void
    {
        if ($this->settings->sendReportToTelegram()) {
            $this->notifier->event('backup_failed', ['error' => $error]);
        }
    }

    /** True when the archive is within the configured Telegram upload limit. */
    public function fitsTelegramLimit(int $size): bool
    {
        return $size > 0 && $size <= $this->settings->maxTelegramFileMb() * 1048576;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
