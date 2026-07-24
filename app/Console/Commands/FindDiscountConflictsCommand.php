<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic: report discount-redemption rows that would violate the
 * reservation-lifecycle unique constraints, so an operator can reconcile them
 * before (or after) the hardening migration. Never modifies data.
 *
 * Exit code 0 = clean, 1 = conflicts found.
 */
class FindDiscountConflictsCommand extends Command
{
    protected $signature = 'zedproxy:find-discount-conflicts';

    protected $description = 'Report conflicting discount redemptions (>1 active per order, or >1 used per order+code). Read-only.';

    public function handle(): int
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
            $this->info('هیچ تداخلی در کدهای تخفیف یافت نشد.');

            return self::SUCCESS;
        }

        $this->error('تداخل در رزرو کدهای تخفیف یافت شد (نیاز به بررسی دستی):');

        if ($activeDupes->isNotEmpty()) {
            $this->line('چند رزرو فعال برای یک سفارش:');
            $this->table(
                ['order_id', 'active reservations'],
                $activeDupes->map(fn ($r) => [$r->order_id, $r->cnt])->all(),
            );
        }

        if ($usedDupes->isNotEmpty()) {
            $this->line('چند ردیف used برای یک سفارش و کد:');
            $this->table(
                ['order_id', 'discount_code_id', 'used rows'],
                $usedDupes->map(fn ($r) => [$r->order_id, $r->discount_code_id, $r->cnt])->all(),
            );
        }

        return self::FAILURE;
    }
}
