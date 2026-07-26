<?php

namespace App\Services\Backup;

use App\Jobs\SendTelegramDocumentJob;
use App\Models\BackupLog;
use App\Models\TelegramAdminTopic;
use App\Services\Telegram\TelegramAdminNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
    /** Overlap-protection lock name + max seconds a backup may hold it. */
    public const LOCK_NAME = 'zedproxy-backup-running';

    public const LOCK_SECONDS = 1800;

    public const STATUS_SKIPPED_LOCKED = 'skipped_locked';

    public function __construct(
        private readonly BackupSettings $settings,
        private readonly TelegramAdminNotifier $notifier,
    ) {}

    /**
     * Run a backup. Never throws. Returns a result summary.
     * Overlap-protected: if another backup holds the lock, returns a skipped
     * result («یک عملیات بکاپ دیگر در حال اجرا است.») without creating a log.
     *
     * @return array{status:string, log_id:?int, path:?string, size:int, duration_ms:int, error:?string}
     */
    public function run(string $type = BackupLog::TYPE_MANUAL, ?bool $forceSendFile = null): array
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_SECONDS);
        if (! $lock->get()) {
            $msg = 'یک عملیات بکاپ دیگر در حال اجرا است.';
            if ($type === BackupLog::TYPE_MANUAL && $this->settings->sendReportToTelegram()) {
                $this->notifier->send('backup_status', $this->settings->topicKey(), 'بکاپ', '💾 '.$msg);
            }

            return ['status' => self::STATUS_SKIPPED_LOCKED, 'log_id' => null, 'path' => null, 'size' => 0, 'duration_ms' => 0, 'error' => $msg];
        }

        try {
            return $this->runLocked($type, $forceSendFile);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string, log_id:?int, path:?string, size:int, duration_ms:int, error:?string} */
    private function runLocked(string $type, ?bool $forceSendFile = null): array
    {
        $log = BackupLog::create(['type' => $type, 'status' => BackupLog::STATUS_STARTED, 'started_at' => now()]);
        $start = microtime(true);
        $work = null;

        try {
            // Fail closed BEFORE any external process runs: an invalid stored
            // path (relative, control chars, traversal) throws a sanitized
            // config failure here — pg_dump/tar never see a CWD-relative path.
            $root = $this->settings->storagePath();
            $this->pathPolicy()->ensureUsableRoot($root);
            $work = $this->createWorkDir($root);

            $sources = [];

            if ($this->settings->includeDatabase()) {
                $dump = $work.'/database.sql';
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
                throw BackupFailure::config('هیچ منبعی برای بکاپ انتخاب نشده است. حداقل یک مورد را در تنظیمات بکاپ فعال کنید.');
            }

            // Build under a temp NON-FINAL name inside the private work dir —
            // same filesystem as the backup root, so commitment below is one
            // atomic rename. A crashed/failed run can never leave a partial
            // file that looks like a completed backup.
            $tmpArchive = $work.'/archive.tar.gz';
            $this->createArchive($tmpArchive, $sources, $this->excludePatterns());
            $this->assertNonEmptyFile($tmpArchive, BackupFailure::archive('tar reported success but produced no usable archive'));

            $candidate = $tmpArchive;
            $final = $root.'/zedproxy-backup-'.now()->format('Ymd-His').'.tar.gz';

            if ($this->settings->encryptEnabled() && $this->settings->hasPassword()) {
                $tmpEncrypted = $tmpArchive.'.enc';
                $this->encryptArchive($tmpArchive, $tmpEncrypted, $this->settings->password());
                // Verify the encrypted artifact BEFORE the plaintext goes away.
                $this->assertNonEmptyFile($tmpEncrypted, BackupFailure::encryption('openssl reported success but produced no usable output'));
                $this->deleteQuietly($tmpArchive);
                $candidate = $tmpEncrypted;
                $final .= '.enc';
            }

            // Atomic commitment: the verified artifact appears under its
            // final name in one rename, or the run fails with nothing
            // half-written in the backup root.
            $this->commitArchive($candidate, $final);

            clearstatcache(true, $final);
            $size = (int) filesize($final);
            // Retention cleanup ONLY after the new backup is committed — a
            // failed run never reduces the set of existing valid backups.
            $cleaned = $this->cleanupOld();
            $duration = (int) round((microtime(true) - $start) * 1000);

            $log->update([
                'status' => BackupLog::STATUS_SUCCESS,
                'file_path' => $final,
                'file_size' => $size,
                'duration_ms' => $duration,
                'finished_at' => now(),
                'metadata' => ['cleaned' => $cleaned],
            ]);

            $this->reportSuccess($log, $final, $size, $duration, $cleaned, $forceSendFile);

            return ['status' => BackupLog::STATUS_SUCCESS, 'log_id' => $log->id, 'path' => $final, 'size' => $size, 'duration_ms' => $duration, 'error' => null];
        } catch (\Throwable $e) {
            // Nothing in this path touches committed zedproxy-backup-* files:
            // a new failure can never remove a previously valid backup.
            $failure = $e instanceof BackupFailure ? $e : BackupFailure::internal($e);
            Log::error('Backup failed', [
                'category' => $failure->category(),
                'detail' => $failure->detail() !== '' ? $failure->detail() : $failure->publicMessage(),
            ]);

            $duration = (int) round((microtime(true) - $start) * 1000);
            // Operator-facing channels (BackupLog/Filament/Telegram) get ONLY
            // the bounded sanitized message — raw process output, paths and
            // credentials-adjacent detail stay in the server log above.
            $msg = mb_substr($failure->publicMessage(), 0, 500);

            $log->update(['status' => BackupLog::STATUS_FAILED, 'error' => $msg, 'duration_ms' => $duration, 'finished_at' => now()]);
            $this->reportFailure($msg);

            return ['status' => BackupLog::STATUS_FAILED, 'log_id' => $log->id, 'path' => null, 'size' => 0, 'duration_ms' => $duration, 'error' => $msg];
        } finally {
            // EVERY exit path — success or failure — clears the work dir and
            // with it the temp dump, temp archive and temp encrypted output.
            // Cleanup problems are logged, never thrown: they must not turn a
            // committed valid backup into a reported failure.
            if ($work !== null) {
                $this->removeDir($work);
            }
        }
    }

    /**
     * Commit a fully verified artifact to its final name with one atomic
     * same-filesystem rename. Refuses to overwrite an existing committed
     * backup and verifies the final artifact after the rename.
     *
     * @throws BackupFailure commit-category failure
     */
    protected function commitArchive(string $candidate, string $final): void
    {
        if (file_exists($final)) {
            throw BackupFailure::commit('final backup path already exists, refusing to overwrite: '.$final);
        }

        // @ only silences the duplicate PHP warning; the result IS checked.
        if (! @rename($candidate, $final)) {
            $err = error_get_last()['message'] ?? 'rename failed';

            throw BackupFailure::commit('rename to final backup name failed: '.$err);
        }

        clearstatcache(true, $final);
        if (! is_file($final) || (int) filesize($final) <= 0) {
            throw BackupFailure::commit('final backup artifact missing or empty after rename: '.$final);
        }
    }

    /**
     * @throws BackupFailure the given failure when the artifact is missing/empty
     */
    private function assertNonEmptyFile(string $path, BackupFailure $failure): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || (int) filesize($path) <= 0) {
            throw $failure;
        }
    }

    private function pathPolicy(): BackupPathPolicy
    {
        return app(BackupPathPolicy::class);
    }

    /**
     * Create a fresh, private, collision-resistant work directory inside the
     * validated backup root. Guarantees:
     *  - the name carries 64 bits of CSPRNG entropy (not a guessable uniqid),
     *  - mkdir() is non-recursive with mode 0700, so an already-existing path
     *    (including one pre-planted by another local user, or leftovers from
     *    a failed run) is NEVER adopted — creation either makes a brand-new
     *    private directory or the backup fails,
     *  - the created directory is verified to physically resolve inside the
     *    backup root (realpath), so a symlinked root component cannot silently
     *    redirect backup artifacts elsewhere,
     *  - no suppressed, unchecked filesystem calls.
     *
     * @throws BackupFailure permission-category failure
     */
    private function createWorkDir(string $root): string
    {
        $work = $root.'/.work_'.bin2hex(random_bytes(8));

        // @ only silences the duplicate PHP warning; the result IS checked.
        if (! @mkdir($work, 0700)) {
            $err = error_get_last()['message'] ?? 'mkdir failed';

            throw BackupFailure::permission(
                'امکان ساخت پوشه کاری بکاپ وجود ندارد. دسترسی‌های مسیر ذخیره بکاپ را بررسی کنید.',
                'mkdir failed for backup work dir '.$work.': '.$err,
            );
        }

        $realWork = realpath($work);
        $realRoot = realpath($root);
        if ($realWork === false || $realRoot === false
            || ! str_starts_with($realWork, rtrim($realRoot, '/').'/')) {
            $this->removeDir($work);

            throw BackupFailure::permission(
                'پوشه کاری بکاپ خارج از مسیر ذخیره بکاپ قرار می‌گیرد و به دلایل امنیتی رد شد.',
                'backup work dir does not resolve inside the backup root: work='.$work.' root='.$root,
            );
        }

        return $realWork;
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
            $cmd[] = '--exclude='.$pat;
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
            throw BackupFailure::config('هیچ‌کدام از منابع انتخاب‌شده برای بکاپ روی سرور وجود ندارند.');
        }

        $result = Process::path(base_path())->timeout(900)->run($cmd);
        if (! $result->successful()) {
            // Raw tar stderr may contain absolute paths — server log only.
            throw BackupFailure::archive('tar failed: '.mb_substr($result->errorOutput() ?: 'unknown', 0, 500));
        }
    }

    /** pg_dump with the password supplied via PGPASSWORD env (never in argv). */
    protected function dumpDatabase(string $target): void
    {
        $conn = (string) config('database.default');
        $cfg = (array) config("database.connections.{$conn}", []);

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
            // pg_dump stderr can leak host/db/username — server log only. The
            // password never appears there (it travels via env, not argv).
            throw BackupFailure::dump('pg_dump failed: '.mb_substr($result->errorOutput() ?: 'unknown', 0, 500));
        }

        $this->assertNonEmptyFile($target, BackupFailure::dump('pg_dump reported success but produced no usable dump file'));
    }

    /**
     * Encrypt $in to $out with openssl (password via env, never argv). The
     * plaintext input is NOT deleted here — the caller verifies the encrypted
     * artifact first and only then discards the plaintext.
     */
    protected function encryptArchive(string $in, string $out, string $password): void
    {
        $result = Process::timeout(900)
            ->env(['ZP_BK_PASS' => $password])
            ->run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-pass', 'env:ZP_BK_PASS', '-in', $in, '-out', $out]);

        if (! $result->successful()) {
            // openssl stderr may contain file paths — server log only.
            throw BackupFailure::encryption('openssl enc failed: '.mb_substr($result->errorOutput() ?: 'unknown', 0, 500));
        }
    }

    /** Delete archives older than the retention window. Returns count removed. */
    public function cleanupOld(): int
    {
        $dir = rtrim($this->settings->storagePath(), '/');
        $cutoff = now()->subDays($this->settings->retentionDays())->getTimestamp();
        $removed = 0;
        foreach (glob($dir.'/zedproxy-backup-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                // @ only silences the duplicate PHP warning; failure is
                // checked, logged, and excluded from the removed count.
                if (@unlink($file)) {
                    $removed++;
                } else {
                    Log::warning('Backup retention cleanup could not delete an expired archive', [
                        'detail' => error_get_last()['message'] ?? 'unlink failed',
                    ]);
                }
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
                'size' => number_format(round($size / 1048576, 2), 2),
                'duration' => (string) round($durationMs / 1000, 1),
                'path' => $this->settings->storagePath(),
                'cleaned' => (string) $cleaned,
            ], $log);
        }

        if ($sendFile && $this->fitsTelegramLimit($size) && is_file($archive)) {
            $thread = TelegramAdminTopic::findByKey($this->settings->topicKey())?->message_thread_id;
            SendTelegramDocumentJob::dispatch($archive, '💾 بکاپ زدپروکسی — '.now()->format('Y/m/d H:i'), $thread, $log->id);
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

    /** Best-effort cleanup: failures are logged, NEVER thrown (a cleanup
     *  problem must not turn a committed valid backup into a failure). */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.'/'.$f;
            is_dir($path) ? $this->removeDir($path) : $this->deleteQuietly($path);
        }
        if (! @rmdir($dir)) {
            Log::warning('Backup cleanup could not remove a work directory', [
                'detail' => error_get_last()['message'] ?? 'rmdir failed',
            ]);
        }
    }

    /** Checked-and-logged delete for temporary artifacts — never throws. */
    private function deleteQuietly(string $path): void
    {
        if (is_file($path) && ! @unlink($path)) {
            Log::warning('Backup cleanup could not delete a temporary file', [
                'detail' => error_get_last()['message'] ?? 'unlink failed',
            ]);
        }
    }
}
