<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harden the discount-redemption reservation lifecycle.
 *
 * Adds the explicit lifecycle timestamps (reserved_at / expires_at / released_at
 * — used_at already exists) and the database-enforced concurrency guarantees:
 *
 *   1. At most ONE active (reserved) reservation per order.
 *   2. At most ONE used redemption per (order, discount code) — so duplicate
 *      payment callbacks can never create duplicate used records.
 *
 * Both are PARTIAL unique indexes so released/expired rows and NULL order_ids do
 * not collide (correct on PostgreSQL; supported by SQLite in tests).
 *
 * Naming: the legacy `cancelled` status is migrated to `released` so the
 * lifecycle is unambiguous (reserved → used | released | expired). Existing rows
 * are UPDATED in place — nothing is deleted or rewritten destructively.
 *
 * SAFETY: conflicting rows are DETECTED before the unique indexes are created and
 * the migration ABORTS with a readable report (run
 * `php artisan zedproxy:find-discount-conflicts` to inspect). Nothing is deleted.
 */
return new class extends Migration
{
    private string $activeIdx = 'discount_redemptions_one_active_per_order';

    private string $usedIdx = 'discount_redemptions_one_used_per_order_code';

    public function up(): void
    {
        if (! Schema::hasTable('discount_redemptions')) {
            return;
        }

        // 1) Lifecycle timestamp columns.
        Schema::table('discount_redemptions', function (Blueprint $table) {
            if (! Schema::hasColumn('discount_redemptions', 'reserved_at')) {
                $table->timestamp('reserved_at')->nullable()->after('final_amount');
            }
            if (! Schema::hasColumn('discount_redemptions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('reserved_at');
            }
            if (! Schema::hasColumn('discount_redemptions', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('used_at');
            }
        });

        // 2) Migrate legacy 'cancelled' → 'released' (data-safe, in place).
        DB::table('discount_redemptions')
            ->where('status', 'cancelled')
            ->update(['status' => 'released', 'released_at' => DB::raw('COALESCE(released_at, updated_at)')]);

        // 3) Backfill lifecycle timestamps for existing rows.
        $ttl = (int) config('zedproxy.discounts.reservation_ttl_minutes', 30);
        DB::table('discount_redemptions')->whereNull('reserved_at')->update([
            'reserved_at' => DB::raw('created_at'),
        ]);
        // Existing reserved rows: give them an expiry so the expirer can reclaim
        // abandoned ones. Use created_at + ttl.
        foreach (DB::table('discount_redemptions')
            ->where('status', 'reserved')->whereNull('expires_at')->select('id', 'created_at')->cursor() as $row) {
            DB::table('discount_redemptions')->where('id', $row->id)->update([
                'expires_at' => Carbon::parse($row->created_at)->addMinutes($ttl),
            ]);
        }

        // 4) Detect conflicts BEFORE adding the unique indexes (never delete).
        $this->abortOnConflicts();

        // 5) Create the partial unique indexes.
        $driver = DB::getDriverName();

        if (! $this->indexExists($this->activeIdx)) {
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement(
                    "CREATE UNIQUE INDEX {$this->activeIdx} ON discount_redemptions (order_id) "
                    ."WHERE status = 'reserved' AND order_id IS NOT NULL"
                );
            } else {
                Schema::table('discount_redemptions', fn ($t) => $t->unique('order_id', $this->activeIdx));
            }
        }

        if (! $this->indexExists($this->usedIdx)) {
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement(
                    "CREATE UNIQUE INDEX {$this->usedIdx} ON discount_redemptions (order_id, discount_code_id) "
                    ."WHERE status = 'used' AND order_id IS NOT NULL"
                );
            } else {
                Schema::table('discount_redemptions', fn ($t) => $t->unique(['order_id', 'discount_code_id'], $this->usedIdx));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('discount_redemptions')) {
            return;
        }
        $driver = DB::getDriverName();
        foreach ([$this->activeIdx, $this->usedIdx] as $idx) {
            if (! $this->indexExists($idx)) {
                continue;
            }
            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement("DROP INDEX IF EXISTS {$idx}");
            } else {
                Schema::table('discount_redemptions', fn ($t) => $t->dropUnique($idx));
            }
        }
        Schema::table('discount_redemptions', function (Blueprint $table) {
            foreach (['reserved_at', 'expires_at', 'released_at'] as $col) {
                if (Schema::hasColumn('discount_redemptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function abortOnConflicts(): void
    {
        $activeDupes = DB::table('discount_redemptions')
            ->select('order_id', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'reserved')
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $usedDupes = DB::table('discount_redemptions')
            ->select('order_id', 'discount_code_id', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'used')
            ->whereNotNull('order_id')
            ->groupBy('order_id', 'discount_code_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($activeDupes->isEmpty() && $usedDupes->isEmpty()) {
            return;
        }

        $lines = [];
        foreach ($activeDupes as $r) {
            $lines[] = "order_id={$r->order_id} → {$r->cnt} active reservations";
        }
        foreach ($usedDupes as $r) {
            $lines[] = "order_id={$r->order_id}, discount_code_id={$r->discount_code_id} → {$r->cnt} used redemptions";
        }

        throw new RuntimeException(
            "Cannot add discount-redemption unique constraints: conflicting rows exist.\n"
            .implode("\n", $lines)."\n"
            .'Resolve them first (nothing was deleted). Run: php artisan zedproxy:find-discount-conflicts'
        );
    }

    private function indexExists(string $name): bool
    {
        $driver = DB::getDriverName();
        try {
            if ($driver === 'pgsql') {
                return DB::table('pg_indexes')->where('indexname', $name)->exists();
            }
            if ($driver === 'sqlite') {
                return DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->exists();
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
