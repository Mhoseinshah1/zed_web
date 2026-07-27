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
 *   • On encrypted runs, verified removal of plaintext temporaries (database
 *     dump, unencrypted archive) is part of the SUCCESS BOUNDARY: the
 *     encrypted artifact is not committed, and the run is not reported
 *     successful, while plaintext material may remain behind.
 *   • Operator channels (BackupLog, Filament, Telegram) and the server log
 *     both receive only sanitized data: bounded Persian messages for
 *     operators; positive-listed fields (category, reason code, exit code,
 *     exception class, log id) for the log. Raw process stderr, exception
 *     text, and filesystem paths are never collected into either.
 *   • The backup root is resolved, validated and canonicalized ONCE per run;
 *     workspace creation, archive commitment, retention cleanup and
 *     reporting all use that single pinned root — a concurrent settings
 *     change only affects future runs.
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
            // ── ONE immutable configuration snapshot per run ────────────────
            // Fail closed BEFORE any external process runs: an invalid stored
            // path (relative, control chars, traversal) throws a sanitized
            // config failure here — pg_dump/tar never see a CWD-relative path.
            // The root is then canonicalized (documented symlink policy: a
            // symlinked root is valid and resolved once) and PINNED: nothing
            // later in the run re-reads the mutable settings, so a concurrent
            // settings change cannot redirect commitment, retention cleanup
            // or reporting to a different directory.
            $root = $this->settings->storagePath();
            $this->pathPolicy()->ensureUsableRoot($root);
            $canonical = realpath($root);
            if ($canonical === false || ! is_dir($canonical)) {
                throw BackupFailure::permission(
                    'پوشه ذخیره بکاپ قابل استفاده نیست. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                    'root_unresolvable',
                );
            }
            $root = rtrim($canonical, '/');

            $encrypt = $this->settings->encryptEnabled() && $this->settings->hasPassword();
            $password = $encrypt ? $this->settings->password() : '';
            $locationLabel = $this->settings->storageLocationLabel();

            $work = $this->createWorkDir($root);

            $sources = [];
            $dump = null;

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
                throw BackupFailure::config('هیچ منبعی برای بکاپ انتخاب نشده است. حداقل یک مورد را در تنظیمات بکاپ فعال کنید.', 'no_sources');
            }

            // Build under a temp NON-FINAL name inside the private work dir —
            // same filesystem as the backup root, so commitment below is one
            // atomic rename. A crashed/failed run can never leave a partial
            // file that looks like a completed backup.
            $tmpArchive = $work.'/archive.tar.gz';
            $this->createArchive($tmpArchive, $sources, $this->excludePatterns());
            $this->assertNonEmptyFile($tmpArchive, BackupFailure::archive('empty_artifact'));

            $candidate = $tmpArchive;
            $final = $root.'/zedproxy-backup-'.now()->format('Ymd-His').'.tar.gz';

            if ($encrypt) {
                $tmpEncrypted = $tmpArchive.'.enc';
                $this->encryptArchive($tmpArchive, $tmpEncrypted, $password);
                // Verify the encrypted artifact BEFORE the plaintext goes away.
                $this->assertNonEmptyFile($tmpEncrypted, BackupFailure::encryption('empty_artifact'));
                // SECURITY BOUNDARY: verified removal of every plaintext
                // temporary is REQUIRED before the encrypted artifact may be
                // committed — an encrypted "success" must never coexist with
                // plaintext residue. Failure here fails the run.
                $this->deletePlaintext($tmpArchive);
                if ($dump !== null) {
                    $this->deletePlaintext($dump);
                }
                $candidate = $tmpEncrypted;
                $final .= '.enc';
            }

            // Atomic commitment: the verified artifact appears under its
            // final name in one rename, or the run fails with nothing
            // half-written in the backup root.
            $this->commitArchive($candidate, $final);

            clearstatcache(true, $final);
            $size = (int) filesize($final);
            // Retention cleanup ONLY after the new backup is committed, and
            // ONLY against the pinned root — a failed run never reduces the
            // set of existing valid backups, and a mid-run settings change
            // can never point cleanup at a different directory.
            $cleaned = $this->cleanupOld($root);
            $duration = (int) round((microtime(true) - $start) * 1000);

            $log->update([
                'status' => BackupLog::STATUS_SUCCESS,
                'file_path' => $final,
                'file_size' => $size,
                'duration_ms' => $duration,
                'finished_at' => now(),
                'metadata' => ['cleaned' => $cleaned],
            ]);

            $this->reportSuccess($log, $final, $size, $duration, $cleaned, $locationLabel, $forceSendFile);

            return ['status' => BackupLog::STATUS_SUCCESS, 'log_id' => $log->id, 'path' => $final, 'size' => $size, 'duration_ms' => $duration, 'error' => null];
        } catch (\Throwable $e) {
            // Nothing in this path touches committed zedproxy-backup-* files:
            // a new failure can never remove a previously valid backup.
            $failure = $e instanceof BackupFailure ? $e : BackupFailure::internal($e);
            // Server log: positive-listed safe fields ONLY. Raw stderr,
            // exception messages, and filesystem paths are never logged.
            Log::error('Backup failed', [
                'backup_log_id' => $log->id,
                'category' => $failure->category(),
                'reason' => $failure->reason(),
                'exit_code' => $failure->exitCode(),
                'exception' => $failure->getPrevious() !== null ? $failure->getPrevious()::class : $failure::class,
            ]);

            $duration = (int) round((microtime(true) - $start) * 1000);
            // Operator-facing channels (BackupLog/Filament/Telegram) get ONLY
            // the bounded sanitized Persian message.
            $msg = mb_substr($failure->publicMessage(), 0, 500);

            $log->update(['status' => BackupLog::STATUS_FAILED, 'error' => $msg, 'duration_ms' => $duration, 'finished_at' => now()]);
            $this->reportFailure($msg);

            return ['status' => BackupLog::STATUS_FAILED, 'log_id' => $log->id, 'path' => null, 'size' => 0, 'duration_ms' => $duration, 'error' => $msg];
        } finally {
            // EVERY exit path — success or failure — clears the work dir.
            // At this point plaintext material on encrypted runs is already
            // gone (security boundary above), so what remains here is
            // NON-CRITICAL housekeeping: problems are logged, never thrown.
            if ($work !== null) {
                $this->removeDir($work);
            }
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
     *  - every filesystem outcome is checked deterministically.
     *
     * @throws BackupFailure permission-category failure
     */
    protected function createWorkDir(string $root): string
    {
        $work = $root.'/.work_'.bin2hex(random_bytes(8));

        if (! CheckedFilesystem::mkdir($work, 0700)) {
            throw BackupFailure::permission(
                'امکان ساخت پوشه کاری بکاپ وجود ندارد. دسترسی‌های مسیر ذخیره بکاپ را بررسی کنید.',
                'workdir_mkdir_failed',
            );
        }

        $realWork = realpath($work);
        $realRoot = realpath($root);
        if ($realWork === false || $realRoot === false
            || ! str_starts_with($realWork, rtrim($realRoot, '/').'/')) {
            $this->removeDir($work);

            throw BackupFailure::permission(
                'پوشه کاری بکاپ خارج از مسیر ذخیره بکاپ قرار می‌گیرد و به دلایل امنیتی رد شد.',
                'workdir_outside_root',
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
            throw BackupFailure::config('هیچ‌کدام از منابع انتخاب‌شده برای بکاپ روی سرور وجود ندارند.', 'sources_missing');
        }

        $result = Process::path(base_path())->timeout(900)->run($cmd);
        if (! $result->successful()) {
            // stderr is deliberately NOT captured anywhere — only the safe
            // numeric exit code travels with the failure.
            throw BackupFailure::archive('process_failed', $result->exitCode());
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
            // pg_dump stderr can carry host/db/username — it is deliberately
            // NOT captured; only the numeric exit code is kept.
            throw BackupFailure::dump('process_failed', $result->exitCode());
        }

        $this->assertNonEmptyFile($target, BackupFailure::dump('empty_artifact'));
    }

    /**
     * Encrypt $in to $out with openssl (password via env, never argv). The
     * plaintext input is NOT deleted here — the caller verifies the encrypted
     * artifact first and then performs the MANDATORY plaintext removal.
     */
    protected function encryptArchive(string $in, string $out, string $password): void
    {
        $result = Process::timeout(900)
            ->env(['ZP_BK_PASS' => $password])
            ->run(['openssl', 'enc', '-aes-256-cbc', '-salt', '-pbkdf2', '-pass', 'env:ZP_BK_PASS', '-in', $in, '-out', $out]);

        if (! $result->successful()) {
            throw BackupFailure::encryption('process_failed', $result->exitCode());
        }
    }

    /**
     * SECURITY-CRITICAL deletion of a plaintext temporary on an encrypted
     * run: the removal must be VERIFIED. If the file cannot be deleted, the
     * run fails with a security-category error and nothing gets committed.
     *
     * @throws BackupFailure plaintext_cleanup-category failure
     */
    protected function deletePlaintext(string $path): void
    {
        if (! $this->unlinkChecked($path)) {
            throw BackupFailure::plaintextCleanup('unlink_failed');
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
            throw BackupFailure::commit('final_exists');
        }

        if (! CheckedFilesystem::rename($candidate, $final)) {
            throw BackupFailure::commit('rename_failed');
        }

        clearstatcache(true, $final);
        if (! is_file($final) || (int) filesize($final) <= 0) {
            throw BackupFailure::commit('final_missing_after_rename');
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

    /**
     * Delete archives older than the retention window. Returns count removed.
     * During a run the PINNED canonical root is passed in; the parameterless
     * form (admin "پاکسازی" action) resolves the setting fresh — that is a
     * separate operation, not part of a backup run.
     */
    public function cleanupOld(?string $root = null): int
    {
        $dir = rtrim($root ?? $this->settings->storagePath(), '/');
        $cutoff = now()->subDays($this->settings->retentionDays())->getTimestamp();
        $removed = 0;
        foreach (glob($dir.'/zedproxy-backup-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                // Non-critical housekeeping: a failed delete is logged (safe
                // fields only) and excluded from the removed count.
                if ($this->unlinkChecked($file)) {
                    $removed++;
                } else {
                    Log::warning('Backup retention cleanup could not delete an expired archive', [
                        'reason' => 'unlink_failed',
                    ]);
                }
            }
        }

        return $removed;
    }

    // ── Telegram reporting ───────────────────────────────────────────────────

    private function reportSuccess(BackupLog $log, string $archive, int $size, int $durationMs, int $cleaned, string $locationLabel, ?bool $forceSendFile = null): void
    {
        $sendFile = $forceSendFile ?? $this->settings->sendFileToTelegram();
        if ($this->settings->sendReportToTelegram()) {
            $this->notifier->event('backup_success', [
                'size' => number_format(round($size / 1048576, 2), 2),
                'duration' => (string) round($durationMs / 1000, 1),
                // {path} stays supported for admin-edited templates, but the
                // value is a non-sensitive LOGICAL location label — never the
                // real filesystem path.
                'path' => $locationLabel,
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

    /**
     * Checked unlink: true when the file is verifiably gone afterwards.
     * A missing file counts as success (nothing to remove).
     */
    protected function unlinkChecked(string $path): bool
    {
        clearstatcache(true, $path);
        if (! file_exists($path)) {
            return true;
        }
        if (! CheckedFilesystem::unlink($path)) {
            return false;
        }
        clearstatcache(true, $path);

        return ! file_exists($path);
    }

    /**
     * NON-CRITICAL housekeeping cleanup: failures are logged with safe
     * fields only, NEVER thrown — by the time this runs on an encrypted
     * run, the security-critical plaintext removal has already happened
     * (or the run has already failed).
     */
    protected function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.'/'.$f;
            if (is_dir($path)) {
                $this->removeDir($path);
            } elseif (! $this->unlinkChecked($path)) {
                Log::warning('Backup cleanup could not delete a temporary file', [
                    'reason' => 'unlink_failed',
                ]);
            }
        }
        if (! CheckedFilesystem::rmdir($dir)) {
            Log::warning('Backup cleanup could not remove a work directory', [
                'reason' => 'rmdir_failed',
            ]);
        }
    }
}
