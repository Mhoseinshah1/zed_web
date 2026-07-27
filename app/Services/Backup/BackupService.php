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
            $root = $this->resolveCanonicalRoot(true);
            if ($root === null) {
                // Unreachable for a backup run (the resolver throws instead),
                // but keeps the shared-resolver contract explicit.
                throw BackupFailure::permission(
                    'پوشه ذخیره بکاپ قابل استفاده نیست. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                    'root_unresolvable',
                );
            }

            // FAIL-CLOSED ENCRYPTION: enabled means MANDATORY. When no usable
            // password exists (never stored, corrupt ciphertext, or encrypted
            // under a different APP_KEY), the run fails HERE — before pg_dump,
            // tar, or any commitment — instead of silently producing a
            // plaintext backup. Encryption is never silently disabled and the
            // toggle is never cleared. The password comes from ONE read + ONE
            // decrypt (resolvePassword) and the local copy is the immutable
            // snapshot for this run — a concurrent settings change can no
            // longer swap or clear it between a state check and a re-read.
            $encrypt = $this->settings->encryptEnabled();
            $password = '';
            if ($encrypt) {
                $resolved = $this->settings->resolvePassword();
                if ($resolved['state'] !== BackupSettings::PASSWORD_OK) {
                    throw BackupFailure::config(
                        'رمزگذاری بکاپ فعال است اما رمز عبور قابل استفاده‌ای ثبت نشده است. در صفحه «بکاپ و سرور» یک رمز عبور جدید ذخیره کنید.',
                        $resolved['state'] === BackupSettings::PASSWORD_NONE
                            ? 'encryption_password_missing'
                            : 'encryption_password_unreadable',
                    );
                }
                $password = $resolved['password'];
            }
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
            // The atomic commitment above IS the backup-success boundary.
            // Retention cleanup runs after it, ONLY against the pinned root,
            // and is NON-FATAL: no retention problem — exception, warning,
            // race, or partial completion — may downgrade the committed
            // backup to failed. Its safe structured outcome is recorded in
            // the log metadata instead.
            $cleanup = $this->safeRetentionCleanup($root, $log->id);
            $duration = (int) round((microtime(true) - $start) * 1000);

            $log->update([
                'status' => BackupLog::STATUS_SUCCESS,
                'file_path' => $final,
                'file_size' => $size,
                'duration_ms' => $duration,
                'finished_at' => now(),
                'metadata' => [
                    'cleaned' => $cleanup['removed'],
                    'cleanup_complete' => $cleanup['complete'],
                    'cleanup_reason' => $cleanup['reason'],
                ],
            ]);

            // ── POST-COMMIT NOTIFICATION BOUNDARY ───────────────────────────
            // The successful backup is fully persisted ABOVE. Everything from
            // here on is optional Telegram delivery behind its own boundary:
            // deliverSuccessNotifications() NEVER throws, so no report,
            // queue-publication, scheduling, or serialization exception can
            // reach the main catch and overwrite a committed success.
            $this->deliverSuccessNotifications($log, $final, $size, $duration, $cleanup['removed'], $locationLabel, $forceSendFile);

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
            // NON-CRITICAL housekeeping. The catch-all guarantees the
            // never-throws contract of run(): NO cleanup problem — mutation,
            // enumeration, or anything unexpected — may escape, replace the
            // recorded result, or flip a committed backup to failed. Only
            // positive-listed safe fields are logged.
            if ($work !== null) {
                try {
                    $this->removeDir($work);
                } catch (\Throwable $cleanupError) {
                    Log::warning('Backup cleanup failed', [
                        'backup_log_id' => $log->id,
                        'stage' => 'workdir_cleanup',
                        'reason' => 'cleanup_exception',
                        'exception' => $cleanupError::class,
                    ]);
                }
            }
        }
    }

    private function pathPolicy(): BackupPathPolicy
    {
        return app(BackupPathPolicy::class);
    }

    /**
     * THE one authoritative canonical-root resolution, shared by backup
     * execution AND standalone/manual retention cleanup — no caller may
     * resolve the configured storage path through a weaker route. It:
     *  - reads + validates the configured path via BackupPathPolicy
     *    (absolute-only, raw control-character rejection),
     *  - rejects a pre-existing resolution to the filesystem root BEFORE
     *    anything touches the filesystem,
     *  - for a backup run ($createIfMissing = true) creates/verifies the
     *    directory (recursive create, exists/dir/writable),
     *  - canonicalizes symlinks once via realpath (valid non-root symlinked
     *    locations stay supported),
     *  - rejects an unresolved, empty, or filesystem-root canonical result,
     *  - never echoes the configured or resolved path in any error.
     *
     * DOCUMENTED POLICY for standalone cleanup ($createIfMissing = false):
     * a missing configured directory is NOT created — there is nothing to
     * clean, so the resolver returns null and cleanup reports zero removals.
     *
     * @return string|null normalized non-root canonical directory, or null
     *                     only in cleanup mode when the directory is absent
     *
     * @throws BackupFailure sanitized config/permission failure
     */
    protected function resolveCanonicalRoot(bool $createIfMissing): ?string
    {
        $configured = $this->settings->storagePath();

        $preExisting = realpath($configured);
        if ($preExisting !== false) {
            $this->assertCanonicalRootAllowed($preExisting);
        } elseif (! $createIfMissing) {
            return null; // standalone cleanup never creates the directory
        }

        if ($createIfMissing) {
            $this->pathPolicy()->ensureUsableRoot($configured);
        }

        $canonical = realpath($configured);
        if ($canonical === false || ! is_dir($canonical)) {
            if (! $createIfMissing) {
                return null;
            }

            throw BackupFailure::permission(
                'پوشه ذخیره بکاپ قابل استفاده نیست. دسترسی‌های مسیر تنظیم‌شده را بررسی کنید.',
                'root_unresolvable',
            );
        }
        $this->assertCanonicalRootAllowed($canonical);

        return rtrim($canonical, '/');
    }

    /**
     * The canonical (realpath'd) backup root must never be the filesystem
     * root: a configured symlink can resolve to "/", and trimming "/" yields
     * an empty string whose children ("/.work_*", "/zedproxy-backup-*") would
     * be filesystem-root entries. Checked BEFORE the work directory is
     * created. The message stays static — the symlink target is not echoed.
     *
     * @throws BackupFailure config-category failure
     */
    private function assertCanonicalRootAllowed(string $canonical): void
    {
        if (rtrim($canonical, '/') === '') {
            throw BackupFailure::config(
                'مسیر ذخیره بکاپ به ریشه فایل‌سیستم منتهی می‌شود و مجاز نیست. یک زیرمسیر مطلق انتخاب کنید.',
                'canonical_root_is_filesystem_root',
            );
        }
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
     * Delete archives older than the retention window. Every filesystem
     * interaction is checked and race-safe: enumeration and mtime lookups go
     * through guarded wrappers, a candidate disappearing between operations
     * counts as already absent, and one bad candidate never aborts the rest.
     * Returns a structured SAFE outcome — never raw errors or paths.
     *
     * During a run the PINNED canonical root is passed in; the parameterless
     * form (admin "پاکسازی" action) goes through the SAME shared canonical
     * resolver (resolveCanonicalRoot) — a symlink to the filesystem root is
     * rejected there before any glob/stat/delete, and a missing directory
     * means "nothing to clean" (it is deliberately never created here).
     *
     * @return array{removed:int, complete:bool, reason:?string}
     *
     * @throws BackupFailure only from standalone root resolution (invalid
     *                       stored path / root resolving to "/") — callers
     *                       surface it as a sanitized notification
     */
    public function cleanupOld(?string $root = null): array
    {
        $dir = $root !== null ? rtrim($root, '/') : $this->resolveCanonicalRoot(false);
        if ($dir === null || $dir === '') {
            return ['removed' => 0, 'complete' => true, 'reason' => null];
        }

        $cutoff = now()->subDays($this->settings->retentionDays())->getTimestamp();
        $removed = 0;
        $complete = true;
        $reason = null;

        $candidates = $this->globBackups($dir.'/zedproxy-backup-*');
        if ($candidates === false) {
            Log::warning('Backup retention cleanup could not enumerate archives', ['reason' => 'glob_failed']);

            return ['removed' => 0, 'complete' => false, 'reason' => 'glob_failed'];
        }

        foreach ($candidates as $file) {
            // Only regular files: a symlink named like a backup is not ours
            // to follow or age-evaluate.
            if (is_link($file) || ! is_file($file)) {
                continue;
            }

            $mtime = $this->fileMtimeChecked($file);
            if ($mtime === false) {
                // Disappeared between enumeration and stat, or unreadable
                // metadata: skip THIS candidate only.
                clearstatcache(true, $file);
                if (! is_link($file) && ! file_exists($file)) {
                    continue; // already absent — deterministic non-event
                }
                $complete = false;
                $reason ??= 'stat_failed';
                Log::warning('Backup retention cleanup could not read archive metadata', ['reason' => 'stat_failed']);

                continue;
            }

            if ($mtime >= $cutoff) {
                continue;
            }

            clearstatcache(true, $file);
            if (! is_link($file) && ! file_exists($file)) {
                continue; // vanished before deletion — already absent, not "removed"
            }

            if ($this->unlinkChecked($file)) {
                $removed++;
            } else {
                $complete = false;
                $reason ??= 'unlink_failed';
                Log::warning('Backup retention cleanup could not delete an expired archive', ['reason' => 'unlink_failed']);
            }
        }

        return ['removed' => $removed, 'complete' => $complete, 'reason' => $reason];
    }

    /**
     * NON-FATAL retention wrapper for the post-commitment phase of a run:
     * the atomic archive commitment is the success boundary, so NOTHING that
     * happens during retention — including an unexpected exception — may
     * downgrade the committed backup. Logged with safe fields only.
     *
     * @return array{removed:int, complete:bool, reason:?string}
     */
    protected function safeRetentionCleanup(string $root, int $logId): array
    {
        try {
            return $this->cleanupOld($root);
        } catch (\Throwable $e) {
            Log::warning('Backup retention cleanup failed', [
                'backup_log_id' => $logId,
                'stage' => 'retention',
                'reason' => 'cleanup_exception',
                'exception' => $e::class,
            ]);

            return ['removed' => 0, 'complete' => false, 'reason' => 'cleanup_exception'];
        }
    }

    /**
     * Checked enumeration seam for retention candidates (guarded glob).
     *
     * @return array<int,string>|false
     */
    protected function globBackups(string $pattern): array|false
    {
        return CheckedFilesystem::glob($pattern);
    }

    /** Checked mtime seam — false instead of a warning/exception on failure. */
    protected function fileMtimeChecked(string $path): int|false
    {
        return CheckedFilesystem::filemtime($path);
    }

    // ── Telegram reporting (post-commit boundary — NEVER throws) ─────────────

    /**
     * Optional Telegram delivery for an ALREADY-PERSISTED successful backup.
     * A locally successful backup and successful Telegram delivery are
     * separate facts: nothing in here — report submission, document-job
     * queue publication, topic lookup, settings reads, serialization — may
     * throw into the caller or change the backup status. Each of the two
     * delivery actions runs behind its own catch so a report failure cannot
     * suppress the document dispatch (and vice versa), and the honest
     * per-action state is merged into the log metadata WITHOUT clobbering
     * the retention keys. Exactly one report and at most one document job
     * are produced per run.
     */
    protected function deliverSuccessNotifications(BackupLog $log, string $archive, int $size, int $durationMs, int $cleaned, string $locationLabel, ?bool $forceSendFile = null): void
    {
        // 1) Success report. 'submitted' means handed to the notifier (which
        //    itself queues fire-and-forget) — not "seen by Telegram".
        try {
            if ($this->settings->sendReportToTelegram()) {
                $this->submitSuccessReport($log, [
                    'size' => number_format(round($size / 1048576, 2), 2),
                    'duration' => (string) round($durationMs / 1000, 1),
                    // {path} stays supported for admin-edited templates, but
                    // the value is a non-sensitive LOGICAL location label —
                    // never the real filesystem path.
                    'path' => $locationLabel,
                    'cleaned' => (string) $cleaned,
                ]);
                $reportState = 'submitted';
            } else {
                $reportState = 'disabled';
            }
        } catch (\Throwable $e) {
            $reportState = 'failed';
            Log::warning('Backup success report could not be submitted', [
                'backup_log_id' => $log->id,
                'stage' => 'telegram_report',
                'reason' => 'telegram_report_failed',
                'exception' => $e::class,
            ]);
        }

        // 2) Document dispatch. 'queued' means the job was PUBLISHED — actual
        //    upload success is still only ever recorded by the job itself via
        //    sent_to_telegram, never here.
        try {
            $sendFile = $forceSendFile ?? $this->settings->sendFileToTelegram();
            if (! $sendFile) {
                $documentState = 'disabled';
            } elseif (! $this->fitsTelegramLimit($size)) {
                $documentState = 'skipped_oversize';
            } elseif (! is_file($archive)) {
                $documentState = 'skipped_missing';
            } else {
                $this->dispatchDocumentJob($archive, $log);
                $documentState = 'queued';
            }
        } catch (\Throwable $e) {
            $documentState = 'dispatch_failed';
            Log::warning('Backup archive could not be scheduled for Telegram delivery', [
                'backup_log_id' => $log->id,
                'stage' => 'telegram_document_dispatch',
                'reason' => 'telegram_document_dispatch_failed',
                'exception' => $e::class,
            ]);
        }

        // 3) Persist honest delivery state (merge — never overwrite the
        //    retention metadata written with the success update).
        try {
            $log->update(['metadata' => array_merge(
                (array) ($log->metadata ?? []),
                ['telegram_report' => $reportState, 'telegram_document' => $documentState],
            )]);
        } catch (\Throwable $e) {
            Log::warning('Backup delivery state could not be persisted', [
                'backup_log_id' => $log->id,
                'stage' => 'delivery_state',
                'reason' => 'delivery_state_persist_failed',
                'exception' => $e::class,
            ]);
        }
    }

    /** Seam: hand the success report to the (fire-and-forget) notifier. */
    protected function submitSuccessReport(BackupLog $log, array $context): void
    {
        $this->notifier->event('backup_success', $context, $log);
    }

    /** Seam: publish the document-upload job to the queue. */
    protected function dispatchDocumentJob(string $archive, BackupLog $log): void
    {
        $thread = TelegramAdminTopic::findByKey($this->settings->topicKey())?->message_thread_id;
        SendTelegramDocumentJob::dispatch($archive, '💾 بکاپ زدپروکسی — '.now()->format('Y/m/d H:i'), $thread, $log->id);
    }

    /**
     * Failure report — same never-throws discipline: a Telegram/settings
     * problem while reporting a failure must not escape run() either, and no
     * second alert is pushed through the same failing Telegram path.
     */
    private function reportFailure(string $error): void
    {
        try {
            if ($this->settings->sendReportToTelegram()) {
                $this->notifier->event('backup_failed', ['error' => $error]);
            }
        } catch (\Throwable $e) {
            Log::warning('Backup failure report could not be submitted', [
                'stage' => 'telegram_report',
                'reason' => 'telegram_report_failed',
                'exception' => $e::class,
            ]);
        }
    }

    /** True when the archive is within the configured Telegram upload limit. */
    public function fitsTelegramLimit(int $size): bool
    {
        return $size > 0 && $size <= $this->settings->maxTelegramFileMb() * 1048576;
    }

    /**
     * Checked unlink: true when the entry is verifiably gone afterwards.
     * A missing entry counts as success — deterministic behavior for files
     * that disappear between enumeration and deletion. Uses is_link() so a
     * dangling symlink (file_exists() false) is still removed as a LINK.
     */
    protected function unlinkChecked(string $path): bool
    {
        clearstatcache(true, $path);
        if (! is_link($path) && ! file_exists($path)) {
            return true;
        }
        if (! CheckedFilesystem::unlink($path)) {
            return false;
        }
        clearstatcache(true, $path);

        return ! is_link($path) && ! file_exists($path);
    }

    /** Checked directory enumeration seam (guarded scandir, never a warning). */
    protected function listDirChecked(string $dir): array|false
    {
        return CheckedFilesystem::scandir($dir);
    }

    /**
     * NON-CRITICAL housekeeping cleanup: failures are logged with safe
     * fields only (no paths, no raw errors), NEVER thrown — by the time
     * this runs on an encrypted run, the security-critical plaintext removal
     * has already happened (or the run has already failed).
     *
     * SYMLINK SAFETY: every entry is checked with is_link() FIRST and a
     * symlink is removed as a LINK — recursion never follows it, so a
     * malicious symlink planted inside a work directory can never make
     * cleanup traverse (or delete) anything outside the work directory.
     * Enumeration itself goes through the checked wrapper, so it cannot
     * emit warnings or abort cleanup.
     */
    protected function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            // Never treat a symlinked directory as ours to empty.
            if (! $this->unlinkChecked($dir)) {
                Log::warning('Backup cleanup could not delete a temporary file', [
                    'reason' => 'unlink_failed',
                ]);
            }

            return;
        }
        if (! is_dir($dir)) {
            return;
        }

        $entries = $this->listDirChecked($dir);
        if ($entries === false) {
            Log::warning('Backup cleanup could not enumerate a work directory', [
                'reason' => 'scandir_failed',
            ]);

            return;
        }

        foreach ($entries as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.'/'.$f;
            if (! is_link($path) && is_dir($path)) {
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
