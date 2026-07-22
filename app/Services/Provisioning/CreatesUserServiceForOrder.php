<?php

namespace App\Services\Provisioning;

use App\Models\Order;
use App\Models\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, race-safe creation of the single UserService for an order.
 *
 * Concurrency strategy (three layers):
 *   1) Row lock — the order row is `lockForUpdate()`ed so concurrent payment
 *      webhooks/callbacks/retries serialize on the create decision.
 *   2) Re-check under the lock — the winner sees no service and creates it; a
 *      loser that already committed is seen here and returned.
 *   3) DB unique index on user_services.order_id — the final guarantee. If two
 *      callers slip past the lock (separate connections/transactions), the
 *      second INSERT throws a unique-violation QueryException, which we catch
 *      and resolve by returning the row the winner created.
 *
 * Returns [UserService $service, bool $created] so callers dispatch the
 * provisioning job / write logs ONLY for the row they actually created.
 */
trait CreatesUserServiceForOrder
{
    /**
     * @param  array<string,mixed>  $attributes
     * @return array{0:UserService,1:bool}
     */
    protected function firstOrCreateServiceForOrder(Order $order, array $attributes): array
    {
        return DB::transaction(function () use ($order, $attributes) {
            // Layer 1 — serialize concurrent callers on the order row.
            Order::whereKey($order->id)->lockForUpdate()->first();

            // Layer 2 — re-check under the lock.
            $existing = UserService::where('order_id', $order->id)->first();
            if ($existing) {
                return [$existing, false];
            }

            // Layer 3 — the unique index is the final guard.
            try {
                $service = UserService::create(array_merge($attributes, ['order_id' => $order->id]));
                return [$service, true];
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $winner = UserService::where('order_id', $order->id)->first();
                    if ($winner) {
                        return [$winner, false];
                    }
                }
                throw $e;
            }
        });
    }

    /** True when the exception is a unique/primary-key violation (pgsql/sqlite/mysql). */
    protected function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        if (in_array($sqlState, ['23505', '23000'], true)) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
    }
}
