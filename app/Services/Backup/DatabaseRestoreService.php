<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Restores the DATABASE portion of an existing ZedProxy backup archive into an
 * already-prepared, EMPTY, non-production PostgreSQL database.
 *
 * ══ WHAT THIS DOES NOT DO ═════════════════════════════════════════════════
 *
 * It never creates, drops, renames, or cuts over a database; it never touches
 * the live application database; and it never restores `public/`, `app/`,
 * `resources/` or any other file payload. Production cutover, filesystem
 * restoration and shared-storage replacement stay manual, deliberate operator
 * actions (see docs/backup-restore-runbook.md).
 *
 * ══ WHY THE TARGET MUST ALREADY EXIST ═════════════════════════════════════
 *
 * Requiring a pre-created target means the application role needs no CREATEDB
 * privilege, this code can never delete an existing database, the operator
 * chooses the owner, and overwriting production through this path is
 * impossible by construction. The target name only ever travels as a single
 * process argument or a bound connection value — never interpolated into SQL
 * or shell text.
 *
 * ══ ARCHIVE HANDLING ══════════════════════════════════════════════════════
 *
 *   1. pin the archive path once (absolute, existing, readable, non-empty,
 *      supported suffix);
 *   2. validate the target database BEFORE any decryption work;
 *   3. decrypt `.enc` archives with openssl, password via the child ENV;
 *   4. INSPECT every entry and reject absolute paths, `.`/`..` segments,
 *      control characters, links, devices, and duplicate/nested/missing
 *      `database.sql`;
 *   5. extract ONLY the single top-level `database.sql`;
 *   6. restore with psql `ON_ERROR_STOP=1 --single-transaction`, so a SQL
 *      error leaves no partially restored schema;
 *   7. run structural checks;
 *   8. shred the work directory on EVERY success and failure path.
 *
 * Compatible with archives the current backup already produces: plain-SQL
 * `pg_dump` output (so `psql`, not `pg_restore`), `tar.gz`, and
 * `aes-256-cbc -salt -pbkdf2` for `.enc`. No manifest is required, so archives
 * created before this command existed restore normally.
 */
class DatabaseRestoreService
{
    /** The ONLY place an archive password is read from (never argv). */
    public const PASSWORD_ENV = 'ZP_BACKUP_RESTORE_PASSWORD';

    /** Databases that may never be a restore target, whatever the operator types. */
    public const RESERVED_DATABASES = ['postgres', 'template0', 'template1'];

    /**
     * Strict PostgreSQL identifier policy for the target name. Deliberately
     * narrower than PostgreSQL allows: unquoted-safe, lowercase, no leading
     * digit, no whitespace/quotes/backslashes, bounded to the 63-byte
     * identifier limit. A name that does not match is REJECTED rather than
     * escaped, so no quoting bug can ever become an injection.
     */
    public const NAME_PATTERN = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** Runtime connection name used to talk to the target (never persisted). */
    private const TARGET_CONNECTION = 'zp_restore_target';

    private const DECRYPT_TIMEOUT = 900;

    private const INSPECT_TIMEOUT = 300;

    private const EXTRACT_TIMEOUT = 900;

    private const RESTORE_TIMEOUT = 1800;

    /** Distinctive prefix so cleanup can never walk an unrelated directory. */
    private const WORK_PREFIX = 'zp-restore-';

    public function __construct(private readonly DumpScriptPolicy $dumpPolicy) {}

    /**
     * Restore $archivePath into the already-existing, empty $targetDatabase.
     *
     * @return array{database:string, archive:string, encrypted:bool, tables:int, migrations:int}
     *
     * @throws RestoreFailure
     */
    public function restore(string $archivePath, string $targetDatabase, ?string $password = null): array
    {
        $source = $this->sourceConnection();
        $target = $this->assertUsableTarget($targetDatabase, $source);
        $archive = $this->assertUsableArchive($archivePath);
        $encrypted = str_ends_with($archive, '.enc');

        $work = $this->makeWorkDirectory();
        $restored = false;

        try {
            $tarball = $encrypted
                ? $this->decrypt($archive, $work, $this->requirePassword($password))
                : $archive;

            $this->assertArchiveIsSafe($tarball);
            $dump = $this->extractDump($tarball, $work);

            // Content validation BEFORE psql exists as a process: psql honours
            // backslash meta-commands from its input file, so `\!` in a dump
            // is remote code execution and a bare COMMIT dissolves the outer
            // transaction. Nothing dangerous may reach the client.
            $this->dumpPolicy->assertSafe($dump);

            // Re-check emptiness immediately before the restore. Validation and
            // decryption take time; this narrows the window in which somebody
            // could have populated the target since the first check.
            $this->assertTargetIsEmpty($target, 'target_not_empty_recheck');

            $this->beforeRestore();
            $this->runPsql($source, $target, $dump);
            $restored = true;

            $result = [
                'database' => $target,
                'archive' => basename($archive),
                'encrypted' => $encrypted,
            ] + $this->verifyRestoredSchema($target);

            // Cleanup is part of the success contract, not an afterthought:
            // an unverified plaintext leftover must never be reported as a
            // clean run.
            $this->cleanup($work, $restored);

            return $result;
        } catch (RestoreFailure $e) {
            $this->cleanup($work, $restored, $e);

            throw $e;
        } catch (\Throwable $e) {
            // Unexpected failures (process launch, timeout, driver errors)
            // never leak their message or trace. The internal failure is built
            // FIRST and handed to cleanup as the primary diagnosis, so a
            // cleanup problem is logged without masking the real cause.
            $internal = RestoreFailure::internal('unexpected_'.$this->safeClass($e));
            $this->cleanup($work, $restored, $internal);

            throw $internal;
        }
    }

    /** Exception class basename reduced to a safe machine token. */
    private function safeClass(\Throwable $e): string
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z]/', '', class_basename($e)));

        return $name === '' ? 'throwable' : substr($name, 0, 40);
    }

    // ── Environment ────────────────────────────────────────────────────────

    /** @return array<string,mixed> the verified PostgreSQL connection config */
    private function sourceConnection(): array
    {
        $name = (string) config('database.default');
        $cfg = (array) config("database.connections.{$name}", []);

        if ((string) ($cfg['driver'] ?? '') !== 'pgsql') {
            throw RestoreFailure::environment(
                'بازیابی بکاپ فقط روی پایگاه‌داده PostgreSQL پشتیبانی می‌شود.',
                'driver_not_pgsql',
            );
        }

        return $cfg;
    }

    // ── Target database ────────────────────────────────────────────────────

    /**
     * The target must be well-formed, non-reserved, not the live database,
     * reachable, and hold NO user-created tables in `public`.
     */
    private function assertUsableTarget(string $requested, array $source): string
    {
        $target = trim($requested);

        if ($target === '' || preg_match(self::NAME_PATTERN, $target) !== 1) {
            throw RestoreFailure::target(
                'نام پایگاه‌داده مقصد نامعتبر است. فقط حروف کوچک، عدد و زیرخط مجاز است.',
                'name_rejected',
            );
        }

        if (in_array($target, self::RESERVED_DATABASES, true)) {
            throw RestoreFailure::target(
                'بازیابی روی پایگاه‌داده‌های سیستمی PostgreSQL مجاز نیست.',
                'reserved_database',
            );
        }

        if ($target === (string) ($source['database'] ?? '')) {
            throw RestoreFailure::target(
                'بازیابی روی پایگاه‌داده فعلی برنامه مجاز نیست. یک پایگاه‌داده خالی جداگانه بسازید.',
                'current_database',
            );
        }

        $this->bindTargetConnection($target, $source);
        $this->assertTargetIsEmpty($target, 'target_not_empty');

        return $target;
    }

    /**
     * The target must hold NO user-created object of ANY kind — not merely no
     * tables. Counting only tables and views would happily restore on top of
     * an existing sequence, materialized view, function, type or custom
     * schema, silently mixing two datasets.
     *
     * Everything PostgreSQL itself requires is excluded: the system catalogs,
     * `information_schema`, any `pg_*` schema (TOAST/temp included), and the
     * default empty `public` schema itself.
     */
    private function assertTargetIsEmpty(string $target, string $reason): void
    {
        try {
            $objects = (int) DB::connection(self::TARGET_CONNECTION)->scalar($this->userObjectCountSql());
        } catch (\Throwable) {
            // Connection/permission detail may name hosts and users — dropped.
            throw RestoreFailure::target(
                'اتصال به پایگاه‌داده مقصد ممکن نشد. وجود پایگاه‌داده و دسترسی کاربر را بررسی کنید.',
                'target_unreachable',
            );
        }

        if ($objects !== 0) {
            throw RestoreFailure::target(
                'پایگاه‌داده مقصد خالی نیست. بازیابی فقط روی یک پایگاه‌داده کاملاً خالی انجام می‌شود.',
                $reason,
            );
        }
    }

    /**
     * Tables, partitioned tables, views, materialized views, sequences and
     * foreign tables; routines; user-defined domains, enums and ranges; and
     * any non-system schema beyond `public`.
     */
    private function userObjectCountSql(): string
    {
        return "select count(*) from (
                    select 1 from pg_class c
                      join pg_namespace n on n.oid = c.relnamespace
                     where n.nspname not in ('pg_catalog', 'information_schema')
                       and n.nspname !~ '^pg_'
                       and c.relkind in ('r', 'p', 'v', 'm', 'S', 'f')
                    union all
                    select 1 from pg_proc p
                      join pg_namespace n on n.oid = p.pronamespace
                     where n.nspname not in ('pg_catalog', 'information_schema')
                       and n.nspname !~ '^pg_'
                    union all
                    select 1 from pg_type t
                      join pg_namespace n on n.oid = t.typnamespace
                     where n.nspname not in ('pg_catalog', 'information_schema')
                       and n.nspname !~ '^pg_'
                       and t.typtype in ('d', 'e', 'r')
                    union all
                    select 1 from pg_namespace n
                     where n.nspname not in ('pg_catalog', 'information_schema', 'public')
                       and n.nspname !~ '^pg_'
                ) as user_objects";
    }

    /**
     * Clone the verified connection with ONLY the database swapped. The name
     * is a bound configuration value, never concatenated into SQL.
     */
    private function bindTargetConnection(string $target, array $source): void
    {
        Config::set('database.connections.'.self::TARGET_CONNECTION, array_merge($source, [
            'database' => $target,
        ]));
        DB::purge(self::TARGET_CONNECTION);
    }

    // ── Archive path ───────────────────────────────────────────────────────

    private function assertUsableArchive(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            throw RestoreFailure::archive('مسیر فایل بکاپ باید مسیر مطلق باشد.', 'not_absolute');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw RestoreFailure::archive('مسیر فایل بکاپ نامعتبر است.', 'control_characters');
        }

        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw RestoreFailure::archive('فایل بکاپ پیدا نشد.', 'not_a_file');
        }

        if (is_link($path)) {
            throw RestoreFailure::archive('مسیر فایل بکاپ نباید پیوند نمادین باشد.', 'path_is_symlink');
        }

        if (! is_readable($real)) {
            throw RestoreFailure::archive('فایل بکاپ قابل خواندن نیست.', 'not_readable');
        }

        if ((int) filesize($real) <= 0) {
            throw RestoreFailure::archive('فایل بکاپ خالی است.', 'empty_file');
        }

        if (! str_ends_with($real, '.tar.gz') && ! str_ends_with($real, '.tar.gz.enc')) {
            throw RestoreFailure::archive('پسوند فایل بکاپ پشتیبانی نمی‌شود.', 'unsupported_suffix');
        }

        return $real;
    }

    // ── Password ───────────────────────────────────────────────────────────

    /**
     * Resolve the archive password. It is NEVER an Artisan argument/option:
     * the command may hand over an interactively prompted value, otherwise it
     * comes from the environment. Missing ⇒ fail closed, before any staging.
     */
    private function requirePassword(?string $supplied): string
    {
        $password = (string) ($supplied ?? getenv(self::PASSWORD_ENV) ?: '');

        if ($password === '') {
            throw RestoreFailure::decryption('password_missing');
        }

        return $password;
    }

    // ── Staging ────────────────────────────────────────────────────────────

    /** Private, owner-only work directory under the system temp root. */
    private function makeWorkDirectory(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/'.self::WORK_PREFIX.bin2hex(random_bytes(12));

        if (! mkdir($dir, 0700, false) || ! is_dir($dir)) {
            throw RestoreFailure::staging('ساخت پوشهٔ موقت بازیابی ممکن نشد.', 'mkdir_failed');
        }
        @chmod($dir, 0700);

        return $dir;
    }

    private function decrypt(string $archive, string $work, string $password): string
    {
        $out = $work.'/archive.tar.gz';

        // Mirrors the backup's own format: aes-256-cbc + salt + PBKDF2, with
        // the password handed over through the CHILD ENV, never argv.
        $result = Process::timeout(self::DECRYPT_TIMEOUT)
            ->env([self::PASSWORD_ENV => $password])
            ->run([
                'openssl', 'enc', '-d', '-aes-256-cbc', '-salt', '-pbkdf2',
                '-pass', 'env:'.self::PASSWORD_ENV,
                '-in', $archive,
                '-out', $out,
            ]);

        if (! $result->successful()) {
            // openssl stderr is deliberately NOT captured: only the exit code.
            throw RestoreFailure::decryption('process_failed', $result->exitCode());
        }

        if (! is_file($out) || (int) filesize($out) <= 0) {
            throw RestoreFailure::decryption('empty_plaintext');
        }

        @chmod($out, 0600);

        return $out;
    }

    // ── Archive inspection ─────────────────────────────────────────────────

    /**
     * Reject anything that could escape the work directory or smuggle a
     * second payload. Runs BEFORE extraction, on the whole entry list.
     */
    private function assertArchiveIsSafe(string $tarball): void
    {
        $result = Process::timeout(self::INSPECT_TIMEOUT)
            ->env(['LC_ALL' => 'C'])
            ->run(['tar', '--numeric-owner', '-tvzf', $tarball]);

        if (! $result->successful()) {
            throw RestoreFailure::content('فایل بکاپ خوانده نشد یا آسیب دیده است.', 'listing_failed');
        }

        $dumps = 0;

        foreach (preg_split('/\R/', (string) $result->output()) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            [$type, $name] = $this->parseListingLine($line);

            if ($type === 'l' || $type === 'h') {
                throw RestoreFailure::content('فایل بکاپ حاوی پیوند است و پذیرفته نمی‌شود.', 'entry_link');
            }
            if ($type !== '-' && $type !== 'd') {
                throw RestoreFailure::content('فایل بکاپ حاوی ورودی غیرمجاز است.', 'entry_special');
            }

            $this->assertSafeEntryName($name);

            if ($name === 'database.sql') {
                if ($type !== '-') {
                    throw RestoreFailure::content('ساختار فایل بکاپ نامعتبر است.', 'dump_not_regular');
                }
                $dumps++;
            } elseif (str_ends_with($name, '/database.sql')) {
                throw RestoreFailure::content('ساختار فایل بکاپ نامعتبر است.', 'dump_nested');
            }
        }

        if ($dumps === 0) {
            throw RestoreFailure::content('فایل بکاپ شامل نسخهٔ پایگاه‌داده نیست.', 'dump_missing');
        }
        if ($dumps > 1) {
            throw RestoreFailure::content('ساختار فایل بکاپ نامعتبر است.', 'dump_duplicated');
        }
    }

    /**
     * Split one `tar -tv` line into [type char, entry name] under LC_ALL=C.
     * The name is everything after the `YYYY-MM-DD HH:MM` stamp, so names
     * containing spaces survive intact; a `a -> b` link suffix is kept so the
     * link check above still sees the entry.
     *
     * @return array{0:string, 1:string}
     */
    private function parseListingLine(string $line): array
    {
        $type = substr($line, 0, 1);

        if (preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?\s(.*)$/', $line, $m) === 1) {
            return [$type, $m[1]];
        }

        // Unrecognized listing shape: refuse rather than guess.
        throw RestoreFailure::content('ساختار فایل بکاپ قابل بررسی نیست.', 'listing_unparsable');
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw RestoreFailure::content('فایل بکاپ حاوی نام ورودی نامعتبر است.', 'entry_control_characters');
        }

        if (str_starts_with($name, '/')) {
            throw RestoreFailure::content('فایل بکاپ حاوی مسیر مطلق است.', 'entry_absolute');
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw RestoreFailure::content('فایل بکاپ حاوی مسیر نسبی ناامن است.', 'entry_traversal');
            }
        }
    }

    /** Extract ONLY `database.sql` — never public/, app/, resources/, … */
    private function extractDump(string $tarball, string $work): string
    {
        $result = Process::timeout(self::EXTRACT_TIMEOUT)
            ->env(['LC_ALL' => 'C'])
            ->run(['tar', '-xzf', $tarball, '-C', $work, 'database.sql']);

        if (! $result->successful()) {
            throw RestoreFailure::content('استخراج نسخهٔ پایگاه‌داده ناموفق بود.', 'extract_failed');
        }

        $dump = $work.'/database.sql';

        if (! is_file($dump) || is_link($dump)) {
            throw RestoreFailure::content('نسخهٔ پایگاه‌داده در فایل بکاپ یافت نشد.', 'dump_absent_after_extract');
        }
        if ((int) filesize($dump) <= 0) {
            throw RestoreFailure::content('نسخهٔ پایگاه‌داده در فایل بکاپ خالی است.', 'dump_empty');
        }

        @chmod($dump, 0600);

        return $dump;
    }

    // ── Restore ────────────────────────────────────────────────────────────

    /**
     * ON_ERROR_STOP=1 + --single-transaction: any SQL error aborts the whole
     * restore and rolls back, so a failed run can never leave a partially
     * restored application schema behind. `-f` avoids shell redirection and
     * the target name is its own argv element.
     */
    private function runPsql(array $source, string $target, string $dump): void
    {
        $result = Process::timeout(self::RESTORE_TIMEOUT)
            ->env(['PGPASSWORD' => (string) ($source['password'] ?? ''), 'LC_ALL' => 'C'])
            ->run([
                'psql',
                '-h', (string) ($source['host'] ?? '127.0.0.1'),
                '-p', (string) ($source['port'] ?? '5432'),
                '-U', (string) ($source['username'] ?? ''),
                '-d', $target,
                '-v', 'ON_ERROR_STOP=1',
                '--single-transaction',
                '--no-password',
                '--quiet',
                '--no-psqlrc',
                '-f', $dump,
            ]);

        if (! $result->successful()) {
            // psql stderr can carry host, database, username and SQL text — it
            // is deliberately NOT captured; only the numeric exit code travels.
            throw RestoreFailure::restore('psql_failed', $result->exitCode());
        }
    }

    // ── Post-restore structural checks ─────────────────────────────────────

    /**
     * Cheap structural sanity only. This is NOT an application-health claim:
     * the runbook requires deeper operator verification before any cutover.
     *
     * @return array{tables:int, migrations:int}
     */
    private function verifyRestoredSchema(string $target): array
    {
        $connection = DB::connection(self::TARGET_CONNECTION);

        try {
            $tables = (int) $connection->scalar($this->userObjectCountSql());

            $present = $connection->select(
                "select table_name from information_schema.tables
                 where table_schema = 'public' and table_name in (?, ?, ?)",
                ['migrations', 'users', 'site_settings'],
            );

            $migrations = (int) $connection->scalar('select count(*) from migrations');
        } catch (\Throwable) {
            throw RestoreFailure::verification(
                'بررسی ساختار پایگاه‌دادهٔ بازیابی‌شده ممکن نشد.',
                'verification_query_failed',
            );
        }

        if ($tables === 0) {
            throw RestoreFailure::verification(
                'پایگاه‌دادهٔ مقصد پس از بازیابی خالی است.',
                'schema_empty',
            );
        }

        $found = array_map(static fn ($row) => (string) $row->table_name, $present);
        foreach (['migrations', 'users', 'site_settings'] as $required) {
            if (! in_array($required, $found, true)) {
                throw RestoreFailure::verification(
                    'جدول‌های اصلی برنامه در نسخهٔ بازیابی‌شده یافت نشدند.',
                    'core_table_missing',
                );
            }
        }

        if ($migrations < 1) {
            throw RestoreFailure::verification(
                'تاریخچهٔ مهاجرت‌ها در نسخهٔ بازیابی‌شده خالی است.',
                'migrations_empty',
            );
        }

        return ['tables' => $tables, 'migrations' => $migrations];
    }

    // ── Cleanup ────────────────────────────────────────────────────────────

    /**
     * CHECKED removal of every plaintext temporary. Deliberately not
     * best-effort: an encrypted archive whose decrypted copy is still on disk
     * is a confidentiality failure, so an unverified leftover fails the run
     * instead of being swallowed.
     *
     * This is deletion (unlink), NOT overwriting — it is not "secure
     * shredding" and is not described as such. On a journalling or
     * copy-on-write filesystem the bytes may survive until reused.
     *
     * $restored says whether the database transaction already committed,
     * because that changes what the operator must be told: when it did, the
     * restore may well have succeeded and blindly rerunning it is the wrong
     * move. An already-failing run keeps ITS failure ($primary) — the caller's
     * diagnosis is more actionable — and the cleanup problem is logged.
     */
    private function cleanup(string $work, bool $restored, ?RestoreFailure $primary = null): void
    {
        $reason = $this->removeWorkDirectory($work);

        if ($reason === null) {
            return;
        }

        $this->safeLog('cleanup', $reason);

        if ($primary === null) {
            throw RestoreFailure::cleanup($reason, $restored);
        }
    }

    /**
     * Delete every staged file and the directory itself, VERIFYING each one.
     * Returns null on success, or a safe reason code for the first failure.
     */
    private function removeWorkDirectory(string $work): ?string
    {
        // Refuse to walk anything that is not one of our own work directories.
        if (! str_starts_with(basename($work), self::WORK_PREFIX)) {
            return 'work_directory_unexpected';
        }
        if (! is_dir($work)) {
            return null; // never created, or already gone
        }

        $entries = @scandir($work);
        if ($entries === false) {
            return 'work_directory_unreadable';
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $work.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                return 'unexpected_directory_entry';
            }

            try {
                $this->unlinkFile($path);
            } catch (\Throwable) {
                // A seam/filesystem error is itself a cleanup failure — it must
                // never escape and replace the caller's diagnosis.
                return 'unlink_error';
            }
            clearstatcache(true, $path);

            if (file_exists($path)) {
                // Name the artifact class, never the path.
                return str_ends_with($entry, '.sql') ? 'dump_not_removed' : 'archive_not_removed';
            }
        }

        try {
            $this->removeDirectory($work);
        } catch (\Throwable) {
            return 'rmdir_error';
        }
        clearstatcache(true, $work);

        return is_dir($work) ? 'work_directory_not_removed' : null;
    }

    /** Seam: tests throw here to prove unexpected failures are sanitized. */
    protected function beforeRestore(): void {}

    /** Filesystem seams: overridden in tests to force verified-cleanup failures. */
    protected function unlinkFile(string $path): void
    {
        @unlink($path);
    }

    protected function removeDirectory(string $path): void
    {
        @rmdir($path);
    }

    /** Positive-listed log: stage + safe machine reason only. */
    private function safeLog(string $stage, string $reason): void
    {
        try {
            Log::warning('[backup-restore] '.$stage, ['stage' => $stage, 'reason' => $reason]);
        } catch (\Throwable) {
            // Logging must never change the restore outcome.
        }
    }
}
