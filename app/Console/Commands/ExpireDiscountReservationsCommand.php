<?php

namespace App\Console\Commands;

use App\Services\Discounts\DiscountService;
use Illuminate\Console\Command;

/**
 * Expire abandoned discount reservations whose hold window has passed, freeing
 * their capacity back to the pool. Scheduled every few minutes.
 *
 * Batched, row-locked (safe against multiple workers), idempotent, and tolerant
 * of a single malformed row. Reports counts; never sends notifications.
 */
class ExpireDiscountReservationsCommand extends Command
{
    protected $signature = 'zedproxy:expire-discount-reservations {--batch= : Rows per batch}';

    protected $description = 'Expire abandoned discount reservations and free their capacity.';

    public function handle(DiscountService $service): int
    {
        $batch = $this->option('batch') !== null ? max(1, (int) $this->option('batch')) : null;

        $result = $service->expireDueReservations($batch);

        $this->info(sprintf(
            'رزروهای منقضی‌شده: %d — سفارش‌های پاک‌سازی‌شده: %d — خطاها: %d',
            $result['expired'],
            $result['orders_cleared'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
