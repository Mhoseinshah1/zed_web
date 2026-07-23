<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a server-side purchase fingerprint to orders and make it the FINAL
 * concurrency guarantee: at most one UNPAID order per (user, fingerprint).
 *
 * A PARTIAL unique index `WHERE purchase_fingerprint IS NOT NULL AND
 * payment_status = 'unpaid'` means:
 *   - Legacy / admin orders (NULL fingerprint) are unaffected — unlimited NULLs.
 *   - Once an order is paid/cancelled/failed it leaves the index, so the user
 *     can legitimately start a fresh purchase.
 *   - Two concurrent identical submissions can never both insert — the loser
 *     hits the unique violation and is routed to the winner's order.
 *
 * Correct on PostgreSQL and supported by SQLite in tests. Existing duplicates
 * (unpaid, same fingerprint) are detected and abort the migration rather than
 * being silently dropped — but no existing row has a fingerprint yet, so this is
 * only a safety net.
 */
return new class extends Migration
{
    private string $indexName = 'orders_user_fingerprint_unpaid_unique';

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'purchase_fingerprint')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('purchase_fingerprint', 64)->nullable()->after('user_id');
                $table->index('purchase_fingerprint');
            });
        }

        if ($this->indexExists()) {
            return;
        }

        // Detect conflicts before adding the constraint (never delete data).
        $dupes = DB::table('orders')
            ->select('user_id', 'purchase_fingerprint', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('purchase_fingerprint')
            ->where('payment_status', 'unpaid')
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->groupBy('user_id', 'purchase_fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes->isNotEmpty()) {
            $lines = $dupes->map(fn ($r) => "user={$r->user_id} fp={$r->purchase_fingerprint} → {$r->cnt}")->implode('; ');
            throw new RuntimeException(
                "Cannot add {$this->indexName}: duplicate unpaid orders share a fingerprint.\n{$lines}\n"
                . 'Resolve them first (nothing was deleted).'
            );
        }

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX {$this->indexName} ON orders (user_id, purchase_fingerprint) "
                . "WHERE purchase_fingerprint IS NOT NULL AND payment_status = 'unpaid' "
                . "AND status NOT IN ('cancelled', 'failed')"
            );
        }
        // MySQL has no partial index; the application-level guard + intent key
        // still protect it there, so we skip the DB index rather than emulate it.
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            $driver = DB::getDriverName();
            if ($this->indexExists() && in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement("DROP INDEX IF EXISTS {$this->indexName}");
            }
            if (Schema::hasColumn('orders', 'purchase_fingerprint')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('purchase_fingerprint');
                });
            }
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
        } catch (\Throwable) {
            return false;
        }
        return false;
    }
};
