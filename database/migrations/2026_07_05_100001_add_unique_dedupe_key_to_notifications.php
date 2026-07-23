<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make notifications.dedupe_key unique (among non-null keys) so the
 * check-then-create in NotificationService can never insert two rows for the
 * same event under concurrency. NULL dedupe_key (notifications without a dedupe
 * marker) stays unconstrained via a partial index.
 *
 * Aborts if pre-existing duplicate dedupe_keys are found (never deletes data).
 */
return new class extends Migration
{
    private string $indexName = 'notifications_dedupe_key_unique';

    public function up(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasColumn('notifications', 'dedupe_key')) {
            return;
        }
        if ($this->indexExists()) {
            return;
        }

        $dupes = DB::table('notifications')
            ->select('dedupe_key', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('dedupe_key')
            ->groupBy('dedupe_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($dupes > 0) {
            throw new RuntimeException(
                "Cannot add unique index on notifications.dedupe_key: {$dupes} duplicate key(s) exist. "
                .'Resolve them first (nothing was deleted).'
            );
        }

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX {$this->indexName} ON notifications (dedupe_key) WHERE dedupe_key IS NOT NULL");
        } else {
            Schema::table('notifications', function ($table) {
                $table->unique('dedupe_key', $this->indexName);
            });
        }
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("DROP INDEX IF EXISTS {$this->indexName}");
        } else {
            Schema::table('notifications', function ($table) {
                $table->dropUnique($this->indexName);
            });
        }
    }

    private function indexExists(): bool
    {
        $driver = DB::getDriverName();
        try {
            if ($driver === 'pgsql') {
                return DB::table('pg_indexes')->where('indexname', $this->indexName)->exists();
            }
            if ($driver === 'sqlite') {
                return DB::table('sqlite_master')->where('type', 'index')->where('name', $this->indexName)->exists();
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
};
