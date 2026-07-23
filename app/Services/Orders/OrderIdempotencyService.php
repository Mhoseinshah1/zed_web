<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PurchaseIntent;
use App\Models\User;
use App\Support\PurchaseToken;
use App\Support\RetriesDeadlocks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Server-enforced idempotency for order creation.
 *
 * The database is the final concurrency guarantee:
 *   - purchase_intents.key is unique — one intent per signed token nonce.
 *   - a partial unique index on orders (user_id, purchase_fingerprint) WHERE
 *     payment_status = 'unpaid' — at most one unpaid order per purchase intent.
 *
 * Double-clicks, browser/network retries, duplicate tabs and truly concurrent
 * requests therefore all converge on exactly one Order.
 */
class OrderIdempotencyService
{
    use RetriesDeadlocks;

    public const MSG_ALREADY       = 'این درخواست قبلاً ثبت شده است.';
    public const MSG_HAS_PENDING   = 'یک سفارش پرداخت‌نشده برای این خرید دارید.';
    public const MSG_REDIRECTED    = 'برای ادامه پرداخت، به سفارش قبلی منتقل شدید.';
    public const MSG_PLAN_INACTIVE = 'این پلن در حال حاضر فعال نیست.';
    public const MSG_EXPIRED       = 'اعتبار درخواست خرید به پایان رسیده است. لطفاً دوباره تلاش کنید.';

    /**
     * Create the order, or return the existing one for a repeated/concurrent
     * submission.
     *
     * @param  array{plan_id?:?int,user_service_id?:?int,options?:array}  $target
     * @param  \Closure(string):Order  $creator  Re-reads the plan/service under
     *         lock, verifies it, computes the server-side price, and creates ONE
     *         Order with purchase_fingerprint set to the given value.
     * @return array{order:Order, reused:bool, message:?string}
     *
     * @throws \RuntimeException Persian message on any validation failure (never retried).
     */
    public function createOrReturn(User $user, string $operation, array $target, ?string $token, \Closure $creator): array
    {
        $planId    = $target['plan_id'] ?? null;
        $serviceId = $target['user_service_id'] ?? null;
        $options   = $target['options'] ?? [];

        $fingerprint = PurchaseToken::fingerprint($user->id, $operation, $planId, $serviceId, $options);

        // Validate the signed token (bound to user + exact purchase target).
        $key = $this->validateToken($token, $user, $operation, $planId, $serviceId, $options);

        return $this->runWithDeadlockRetries(function () use ($user, $operation, $planId, $serviceId, $fingerprint, $key, $creator) {
            return DB::transaction(function () use ($user, $operation, $planId, $serviceId, $fingerprint, $key, $creator) {
                // ── 1. Claim the idempotency record (unique key = final guard). ──
                $intent = null;
                if ($key !== null) {
                    $intent = $this->claimIntent($key, $user, $operation, $planId, $serviceId, $fingerprint);

                    if ((int) $intent->user_id !== (int) $user->id) {
                        throw new \RuntimeException(self::MSG_EXPIRED); // cross-user reuse
                    }
                    if ($intent->isConsumed() && $intent->order_id) {
                        // Exact same request already produced an order → return it.
                        return ['order' => Order::findOrFail($intent->order_id), 'reused' => true, 'message' => self::MSG_ALREADY];
                    }
                    if ($intent->isExpired()) {
                        throw new \RuntimeException(self::MSG_EXPIRED);
                    }
                }

                // ── 2. Recent unpaid order for the same intent → redirect to it. ──
                $window   = (int) config('zedproxy.purchase.pending_reuse_minutes', 30);
                $existing = Order::where('user_id', $user->id)
                    ->where('purchase_fingerprint', $fingerprint)
                    ->where('payment_status', Order::PAYMENT_UNPAID)
                    ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED])
                    ->where('created_at', '>=', now()->subMinutes(max(1, $window)))
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($intent) {
                        $intent->update(['order_id' => $existing->id, 'status' => PurchaseIntent::STATUS_CONSUMED, 'consumed_at' => now()]);
                    }
                    return ['order' => $existing, 'reused' => true, 'message' => self::MSG_HAS_PENDING];
                }

                // ── 3. Create exactly one order (server-side price + fingerprint). ──
                $reusedByRace = false;
                try {
                    $order = $creator($fingerprint);
                } catch (QueryException $e) {
                    // A concurrent request won the partial-unique race — return its order.
                    $order = Order::where('user_id', $user->id)
                        ->where('purchase_fingerprint', $fingerprint)
                        ->where('payment_status', Order::PAYMENT_UNPAID)
                        ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED])
                        ->orderByDesc('id')
                        ->first();
                    if (! $order) {
                        throw $e;
                    }
                    $reusedByRace = true;
                }

                if ($intent) {
                    $intent->update(['order_id' => $order->id, 'status' => PurchaseIntent::STATUS_CONSUMED, 'consumed_at' => now()]);
                }

                return ['order' => $order, 'reused' => $reusedByRace, 'message' => $reusedByRace ? self::MSG_HAS_PENDING : null];
            });
        }, 'order.idempotency');
    }

    /**
     * Lock the intent by key, creating it on first use. The unique constraint on
     * `key` serializes concurrent first-time claims — the loser re-reads.
     */
    private function claimIntent(string $key, User $user, string $operation, ?int $planId, ?int $serviceId, string $fingerprint): PurchaseIntent
    {
        $intent = PurchaseIntent::where('key', $key)->lockForUpdate()->first();
        if ($intent) {
            return $intent;
        }

        try {
            return PurchaseIntent::create([
                'key'                 => $key,
                'user_id'             => $user->id,
                'operation_type'      => $operation,
                'plan_id'             => $planId,
                'user_service_id'     => $serviceId,
                'request_fingerprint' => $fingerprint,
                'status'              => PurchaseIntent::STATUS_PENDING,
                'expires_at'          => now()->addMinutes(max(1, (int) config('zedproxy.purchase.intent_ttl_minutes', 30))),
            ]);
        } catch (QueryException $e) {
            // Concurrent first-claim won the unique(key) race — read theirs (blocks
            // until their transaction commits, then we see the consumed intent).
            $intent = PurchaseIntent::where('key', $key)->lockForUpdate()->first();
            if (! $intent) {
                throw $e;
            }
            return $intent;
        }
    }

    /**
     * Validate the signed token and return its nonce (the idempotency key), or
     * null when no token was supplied (fingerprint-only protection still applies).
     *
     * @throws \RuntimeException MSG_EXPIRED when the token is invalid/expired/for
     *         another user/for a different plan (i.e. tampered after issue).
     */
    private function validateToken(?string $token, User $user, string $operation, ?int $planId, ?int $serviceId, array $options): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $payload = PurchaseToken::parse($token);
        if ($payload === null) {
            throw new \RuntimeException(self::MSG_EXPIRED); // invalid / tampered / missing
        }

        // Bound to the authenticated user, the operation, and the routing target
        // service. The plan is enforced only when the token pinned one (the buy
        // flow, where the plan is the URL parameter): pinning it and then changing
        // the plan is rejected. For renewal/add-ons the plan/amount are chosen in
        // the form and re-validated server-side, so they are not pinned here.
        if ((int) $payload['u'] !== (int) $user->id) {
            throw new \RuntimeException(self::MSG_EXPIRED); // another user's token
        }
        if ($payload['op'] !== $operation) {
            throw new \RuntimeException(self::MSG_EXPIRED);
        }
        if (($payload['s'] ?? null) !== $serviceId) {
            throw new \RuntimeException(self::MSG_EXPIRED);
        }
        if (($payload['p'] ?? null) !== null && (int) $payload['p'] !== (int) $planId) {
            throw new \RuntimeException(self::MSG_EXPIRED); // plan changed after issue
        }

        $ttl = (int) config('zedproxy.purchase.intent_ttl_minutes', 30);
        if (($payload['iat'] + $ttl * 60) < now()->getTimestamp()) {
            throw new \RuntimeException(self::MSG_EXPIRED); // expired
        }

        return $payload['n'];
    }

    /**
     * Prune expired, unconsumed intents. Batched, idempotent, and never touches
     * intents that produced an order.
     *
     * @return int number pruned
     */
    public function pruneExpired(?int $batchSize = null): int
    {
        $batchSize = $batchSize ?? max(1, (int) config('zedproxy.purchase.prune_batch_size', 500));
        $total = 0;

        while (true) {
            $ids = PurchaseIntent::prunable()->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            // Re-check prunability inside delete so a concurrent submission that
            // just consumed one is never removed.
            $total += PurchaseIntent::whereIn('id', $ids)
                ->where('status', PurchaseIntent::STATUS_PENDING)
                ->whereNull('order_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->delete();

            if ($ids->count() < $batchSize) {
                break;
            }
        }

        return $total;
    }
}
