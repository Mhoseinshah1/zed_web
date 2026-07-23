<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderIdempotencyService;
use Illuminate\Console\Command;

/**
 * Delete expired, unconsumed purchase intents. Batched and idempotent; never
 * deletes an intent that produced an order (order_id is kept for audit), and is
 * safe to run alongside live submissions (it re-checks prunability at delete time).
 */
class PrunePurchaseIntentsCommand extends Command
{
    protected $signature = 'zedproxy:prune-purchase-intents {--batch= : Rows per batch}';

    protected $description = 'Prune expired, unconsumed purchase intents.';

    public function handle(OrderIdempotencyService $service): int
    {
        $batch = $this->option('batch') !== null ? max(1, (int) $this->option('batch')) : null;

        $pruned = $service->pruneExpired($batch);

        $this->info("intent‌های منقضی‌شده پاک‌سازی شد: {$pruned}");

        return self::SUCCESS;
    }
}
