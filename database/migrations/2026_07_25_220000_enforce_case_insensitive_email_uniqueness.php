<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB-level guarantee that two addresses differing only by case (or stray
 * whitespace) can never coexist: a functional unique index on lower(email).
 *
 * SAFE + guarded:
 *  1. Existing case-insensitive duplicates ABORT the migration with a clear
 *     message and NO data modified — an operator must resolve them manually.
 *  2. Non-conflicting mixed-case emails are normalized to lower(trim(email));
 *     email_verified_at (and every other column) is untouched.
 *  3. The index is created with IF NOT EXISTS (idempotent re-runs) on
 *     PostgreSQL and SQLite, which both support expression indexes. Other
 *     drivers keep the application-level guarantees (model normalization +
 *     case-insensitive validation) without a functional index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Detect duplicates FIRST — fail clearly, modify nothing.
        $duplicates = DB::table('users')
            ->selectRaw('lower(trim(email)) as normalized_email, count(*) as occurrences')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot enforce case-insensitive email uniqueness: %d set(s) of user emails differ only by case/whitespace (%d rows total). '
                .'Resolve these accounts manually, then re-run the migration. No data was modified.',
                $duplicates->count(),
                (int) $duplicates->sum('occurrences'),
            ));
        }

        // 2) Normalize existing non-conflicting addresses in place.
        //    email_verified_at is intentionally NOT touched.
        DB::table('users')
            ->whereRaw('email != lower(trim(email))')
            ->update(['email' => DB::raw('lower(trim(email))')]);

        // 3) Functional unique index — the database itself now refuses a
        //    second address that differs only by case.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users ((lower(email)))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        }
    }
};
