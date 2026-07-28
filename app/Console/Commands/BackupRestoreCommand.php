<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\RestoreFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * zedproxy:backup-restore — restore the DATABASE portion of an existing
 * backup archive into an already-prepared, EMPTY, non-production database.
 *
 * This command is deliberately narrow. It does not create, drop or cut over a
 * database, does not touch the live application database, and restores no
 * file payload. Production cutover stays a manual, planned operator action —
 * see docs/backup-restore-runbook.md.
 *
 * The archive password is NEVER an argument or option (argv is world-readable
 * in the process list and lands in shell history). It comes from the
 * ZP_BACKUP_RESTORE_PASSWORD environment variable, with a hidden prompt as an
 * interactive-only fallback; a non-interactive run without it fails closed.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'zedproxy:backup-restore
        {archive : Absolute path to a .tar.gz or .tar.gz.enc backup archive}
        {--target-database= : Name of an EXISTING, EMPTY PostgreSQL database to restore into}';

    protected $description = 'Restore a backup archive database into a pre-created empty PostgreSQL database (never production).';

    public function handle(DatabaseRestoreService $restore): int
    {
        $archive = (string) $this->argument('archive');
        $target = (string) ($this->option('target-database') ?? '');

        if ($target === '') {
            $this->error('--target-database is required (an EXISTING, EMPTY database).');

            return self::INVALID;
        }

        try {
            $result = $restore->restore($archive, $target, $this->resolvePassword());
        } catch (RestoreFailure $e) {
            // Only the bounded public sentence reaches the operator; the safe
            // machine reason and numeric exit code go to the server log.
            $this->error($e->publicMessage());
            $this->safeLog($archive, $target, $e);

            return self::FAILURE;
        } catch (\Throwable $e) {
            // Belt and braces: the service already sanitizes, but nothing
            // unexpected may ever reach the console or the log with its own
            // message or stack trace.
            $internal = RestoreFailure::internal('unexpected_error');
            $this->error($internal->publicMessage());
            $this->safeLog($archive, $target, $internal, class_basename($e));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restore completed: archive=%s encrypted=%s target=%s tables=%d migrations=%d',
            $result['archive'],
            $result['encrypted'] ? 'yes' : 'no',
            $result['database'],
            $result['tables'],
            $result['migrations'],
        ));
        $this->line('Structural checks only — verify the application against this database before any cutover.');

        return self::SUCCESS;
    }

    /**
     * Environment first; a hidden prompt is offered ONLY when the console is
     * genuinely interactive. Returning null lets the service fail closed for
     * an encrypted archive, while leaving plain archives unaffected.
     */
    private function resolvePassword(): ?string
    {
        $fromEnv = (string) (getenv(DatabaseRestoreService::PASSWORD_ENV) ?: '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        // A hidden prompt is only meaningful at a real terminal. `isInteractive()`
        // alone is NOT enough: Artisan::call() and other programmatic callers use
        // an ArrayInput that reports itself interactive, so prompting there would
        // block forever on a stdin nobody is going to type into (CI, cron, a
        // deploy script). Require an actual TTY, otherwise fail closed.
        if (! $this->input->isInteractive() || ! defined('STDIN') || ! stream_isatty(STDIN)) {
            return null;
        }

        $typed = (string) $this->secret('Archive password (leave empty for an unencrypted archive)');

        return $typed !== '' ? $typed : null;
    }

    /** Positive-listed log: stage, safe reason code, exit code, safe labels. */
    private function safeLog(string $archive, string $target, RestoreFailure $e, ?string $exceptionClass = null): void
    {
        try {
            Log::warning('[backup-restore] failed', array_filter([
                'stage' => $e->category(),
                'reason' => $e->reason(),
                'exit_code' => $e->exitCode(),
                'archive' => $this->archiveLabel($archive),
                'target' => preg_match(DatabaseRestoreService::NAME_PATTERN, $target) === 1 ? $target : 'rejected',
                'exception' => $exceptionClass === null
                    ? null
                    : substr((string) preg_replace('/[^A-Za-z0-9_]/', '', $exceptionClass), 0, 60),
            ], static fn ($value) => $value !== null));
        } catch (\Throwable) {
            // Logging must never mask the restore outcome.
        }
    }

    /**
     * A filename is attacker-influenced text. Reduce it to a basename, strip
     * control characters and Unicode line/paragraph separators (so it cannot
     * forge extra log lines or fields), collapse whitespace, and bound the
     * length. Falls back to a static label when nothing safe survives.
     */
    private function archiveLabel(string $archive): string
    {
        $label = basename($archive);
        $label = (string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', '', $label);
        $label = (string) preg_replace('/\s+/u', ' ', $label);
        $label = trim($label);

        if ($label === '') {
            return 'archive';
        }

        return function_exists('mb_substr') ? mb_substr($label, 0, 120) : substr($label, 0, 120);
    }
}
