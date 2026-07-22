<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make user_services.order_id unique — the final, database-enforced guarantee
 * that one order can produce at most one service, closing the payment/
 * provisioning race.
 *
 * NULL handling: many rows may legitimately have order_id = NULL (admin-created
 * services). A PARTIAL unique index (`WHERE order_id IS NOT NULL`) enforces
 * uniqueness only among real orders while allowing unlimited NULLs — the correct
 * behaviour on PostgreSQL (and supported by SQLite in tests).
 *
 * SAFETY: existing duplicates are DETECTED and the migration ABORTS with a
 * clear report instead of silently dropping data. Resolve them first with
 * `php artisan zedproxy:find-duplicate-services`. Nothing is auto-deleted.
 */
return new class extends Migration
{
    private string $indexName = 'user_services_order_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('user_services') || ! Schema::hasColumn('user_services', 'order_id')) {
            return;
        }
        if ($this->indexExists()) {
            return; // idempotent
        }

        // 1) Detect duplicates BEFORE adding the constraint (never delete).
        $dupes = DB::table('user_services')
            ->select('order_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes->isNotEmpty()) {
            $lines = $dupes->map(fn ($r) => "order_id={$r->order_id} → {$r->cnt} services")->implode('; ');
            throw new RuntimeException(
                "Cannot add the unique constraint on user_services.order_id: duplicate services exist.\n"
                . "Duplicates: {$lines}\n"
                . "Resolve them first (nothing was deleted). Run: php artisan zedproxy:find-duplicate-services"
            );
        }

        // 2) Add the partial unique index (portable across pgsql + sqlite).
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX {$this->indexName} ON user_services (order_id) WHERE order_id IS NOT NULL");
        } else {
            // Fallback: a plain unique index (nullable columns still allow
            // multiple NULLs on MySQL, so behaviour is equivalent).
            Schema::table('user_services', function ($table) {
                $table->unique('order_id', $this->indexName);
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
            Schema::table('user_services', function ($table) {
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
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
};
