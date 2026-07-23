<?php

namespace App\Services\Discounts;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\RetriesDeadlocks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Discount reservation lifecycle with concurrency-safe capacity control.
 *
 * Deterministic lock order EVERYWHERE: DiscountCode is locked BEFORE the Order.
 * Capacity is (re)counted only after the row locks are held, so concurrent
 * requests can never over-reserve. Reservations temporarily hold capacity until
 * paid (→ used), removed/cancelled (→ released) or abandoned (→ expired).
 *
 * External services (payment, Telegram, mail, panels) are NEVER called while a
 * DB lock is held — notifications fire after the transaction commits.
 */
class DiscountService
{
    use RetriesDeadlocks;

    // User-facing Persian messages.
    public const MSG_CAPACITY_FULL   = 'ظرفیت استفاده از این کد تخفیف تکمیل شده است.';
    public const MSG_PER_USER_LIMIT  = 'شما قبلاً به حداکثر تعداد استفاده از این کد رسیده‌اید.';
    public const MSG_RESERVATION_EXP  = 'زمان رزرو این کد تخفیف به پایان رسیده است.';
    public const MSG_APPLIED          = 'کد تخفیف با موفقیت اعمال شد.';
    public const MSG_REMOVED          = 'کد تخفیف از سفارش حذف شد.';
    public const MSG_ORDER_HAS_USED   = 'این سفارش قبلاً از یک کد تخفیف استفاده کرده است.';

    private function ttlMinutes(): int
    {
        return max(1, (int) config('zedproxy.discounts.reservation_ttl_minutes', 30));
    }

    // ── Read-only preview (UI). The authoritative check runs under lock in apply. ─

    /**
     * @return array{valid:bool, message:string, discount_code:?DiscountCode, discount_amount:int}
     */
    public function validateCode(User $user, Order $order, string $code): array
    {
        $discountCode = $this->resolveCode($code);
        if (! $discountCode) {
            return $this->invalid('این کد تخفیف معتبر نیست.');
        }

        if ($error = $this->checkRules($discountCode, $order, $user)) {
            return $this->invalid($error);
        }
        if ($error = $this->checkCapacity($discountCode, $user, $order)) {
            return $this->invalid($error);
        }

        return [
            'valid'           => true,
            'message'         => self::MSG_APPLIED,
            'discount_code'   => $discountCode,
            'discount_amount' => $this->calculateDiscount($order, $discountCode),
        ];
    }

    /**
     * Calculate the discount amount in toman. Integer-based, never negative,
     * never larger than the order amount.
     */
    public function calculateDiscount(Order $order, DiscountCode $discountCode): int
    {
        $base = max(0, (int) $order->price_toman);

        if ($discountCode->type === DiscountCode::TYPE_PERCENT) {
            $amount = (int) round($base * $discountCode->value / 100);
            if ($discountCode->max_discount_amount !== null) {
                $amount = min($amount, (int) $discountCode->max_discount_amount);
            }
            return max(0, min($amount, $base));
        }

        return max(0, min((int) $discountCode->value, $base));
    }

    // ── Apply / replace (atomic, locked, retried) ────────────────────────────

    /**
     * Apply (or replace) a discount on an order. Atomic + concurrency-safe.
     *
     * @throws \RuntimeException Persian message on any validation failure.
     */
    public function applyToOrder(User $user, Order $order, string $code): Order
    {
        // Existence check outside the tx for a friendly message; the row is
        // re-fetched and LOCKED inside the transaction.
        $codeModel = $this->resolveCode($code);
        if (! $codeModel) {
            throw new \RuntimeException('این کد تخفیف معتبر نیست.');
        }

        return $this->runWithDeadlockRetries(function () use ($user, $order, $codeModel) {
            return DB::transaction(function () use ($user, $order, $codeModel) {
                // (2) Lock the discount code row FIRST (deterministic order).
                $discountCode = DiscountCode::whereKey($codeModel->id)->lockForUpdate()->first();
                if (! $discountCode) {
                    throw new \RuntimeException('این کد تخفیف معتبر نیست.');
                }

                // (3,4) Reload + lock the order row.
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw new \RuntimeException('سفارش یافت نشد.');
                }

                // (5) Ownership.
                if ((int) $locked->user_id !== (int) $user->id) {
                    throw new \RuntimeException('این سفارش متعلق به شما نیست.');
                }

                // (6) Unpaid + eligible.
                $this->assertOrderEligible($locked);

                // (7) Re-evaluate every rule under the lock.
                if ($error = $this->checkRules($discountCode, $locked, $user)) {
                    throw new \RuntimeException($error);
                }

                // (8) Capacity: used + non-expired reserved (excluding this order's own).
                if ($error = $this->checkCapacity($discountCode, $user, $locked)) {
                    throw new \RuntimeException($error);
                }

                // Replace: release any previous active reservation on this order.
                $this->releaseActive($locked, 'replaced');

                // (9) Reserve atomically. The partial unique index is the final wall.
                $discountAmount = $this->calculateDiscount($locked, $discountCode);
                $finalAmount    = max(0, (int) $locked->price_toman - $discountAmount);

                try {
                    DiscountRedemption::create([
                        'discount_code_id' => $discountCode->id,
                        'user_id'          => $user->id,
                        'order_id'         => $locked->id,
                        'status'           => DiscountRedemption::STATUS_RESERVED,
                        'original_amount'  => (int) $locked->price_toman,
                        'discount_amount'  => $discountAmount,
                        'final_amount'     => $finalAmount,
                        'reserved_at'      => now(),
                        'expires_at'       => now()->addMinutes($this->ttlMinutes()),
                    ]);
                } catch (QueryException $e) {
                    // A concurrent request beat us to the single active-reservation
                    // slot for this order — surface as capacity contention.
                    throw new \RuntimeException(self::MSG_CAPACITY_FULL);
                }

                // (10) Order discount snapshot inside the same transaction.
                $locked->update([
                    'discount_code_id'  => $discountCode->id,
                    'discount_code'     => $discountCode->code,
                    'discount_type'     => $discountCode->type,
                    'discount_value'    => $discountCode->value,
                    'discount_toman'    => $discountAmount,
                    'final_price_toman' => $finalAmount,
                ]);

                return $locked->fresh();
            });
        }, 'discount.apply');
    }

    // ── Remove (atomic) ───────────────────────────────────────────────────────

    public function removeFromOrder(Order $order): void
    {
        $this->runWithDeadlockRetries(function () use ($order) {
            DB::transaction(function () use ($order) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || $locked->payment_status === Order::PAYMENT_PAID) {
                    return; // never touch a paid order
                }

                $this->releaseActive($locked, 'removed');

                $locked->update([
                    'discount_code_id'  => null,
                    'discount_code'     => null,
                    'discount_type'     => null,
                    'discount_value'    => null,
                    'discount_toman'    => 0,
                    'final_price_toman' => (int) $locked->price_toman, // recalculated server-side
                ]);
            });
        }, 'discount.remove');
    }

    // ── Payment completion (idempotent, snapshot is source of truth) ──────────

    /**
     * Convert the order's reservation to `used` exactly once. Safe against
     * duplicate IPNs / callbacks / manual approvals.
     */
    public function markUsed(Order $order): void
    {
        if (! $order->discount_code_id) {
            return;
        }

        $shouldNotify = false;

        $this->runWithDeadlockRetries(function () use ($order, &$shouldNotify) {
            DB::transaction(function () use ($order, &$shouldNotify) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->discount_code_id) {
                    return;
                }
                // Only a genuinely paid order may consume a redemption.
                if ($locked->payment_status !== Order::PAYMENT_PAID) {
                    return;
                }

                // Already used → return the existing result, do NOT re-notify.
                $existingUsed = DiscountRedemption::where('order_id', $locked->id)
                    ->where('status', DiscountRedemption::STATUS_USED)
                    ->lockForUpdate()
                    ->exists();
                if ($existingUsed) {
                    return;
                }

                // Convert the reservation; if it lapsed, honour the ORDER SNAPSHOT
                // (never recompute the historic discount from current code settings).
                $reservation = DiscountRedemption::where('order_id', $locked->id)
                    ->where('discount_code_id', $locked->discount_code_id)
                    ->where('status', DiscountRedemption::STATUS_RESERVED)
                    ->lockForUpdate()
                    ->first();

                try {
                    if ($reservation) {
                        $reservation->update([
                            'status'          => DiscountRedemption::STATUS_USED,
                            'used_at'         => now(),
                            'discount_amount' => (int) $locked->discount_toman,
                            'final_amount'    => (int) $locked->final_price_toman,
                            'original_amount' => (int) $locked->price_toman,
                        ]);
                    } else {
                        DiscountRedemption::create([
                            'discount_code_id' => $locked->discount_code_id,
                            'user_id'          => $locked->user_id,
                            'order_id'         => $locked->id,
                            'status'           => DiscountRedemption::STATUS_USED,
                            'original_amount'  => (int) $locked->price_toman,
                            'discount_amount'  => (int) $locked->discount_toman,
                            'final_amount'     => (int) $locked->final_price_toman,
                            'reserved_at'      => now(),
                            'used_at'          => now(),
                        ]);
                    }
                } catch (QueryException $e) {
                    // Unique (order, code) used index tripped by a concurrent
                    // caller — it already recorded the used redemption.
                    return;
                }

                $shouldNotify = true;
            });
        }, 'discount.markUsed');

        // Notify AFTER commit — never inside a lock. Dedupe key guarantees one.
        if ($shouldNotify && $order->user) {
            app(NotificationService::class)->notify(
                Notification::TYPE_DISCOUNT_USED,
                $order->user,
                [
                    'user_name'       => $order->user->name ?? $order->user->username,
                    'order_id'        => $order->order_number,
                    'discount_amount' => number_format($order->discount_toman),
                    'final_amount'    => number_format($order->final_price_toman),
                ],
                'discount_used:order:' . $order->id,
            );
        }
    }

    // ── Lifecycle release (cancel / expire / fail / admin invalidate) ─────────

    /**
     * Release the active reservation of an order (order cancelled/failed/expired
     * or replaced). Never touches a paid order's used redemption.
     */
    public function releaseReservation(Order $order, string $reason = 'released'): void
    {
        $this->runWithDeadlockRetries(function () use ($order, $reason) {
            DB::transaction(function () use ($order, $reason) {
                $locked = Order::whereKey($order->id)->lockForUpdate()->first();
                if (! $locked || $locked->payment_status === Order::PAYMENT_PAID) {
                    return;
                }
                $this->releaseActive($locked, $reason);
            });
        }, 'discount.release');
    }

    /**
     * Expire abandoned reservations in batches. Idempotent; one malformed row
     * never fails the whole batch. Returns counts.
     *
     * @return array{expired:int, orders_cleared:int, errors:int}
     */
    public function expireDueReservations(?int $batchSize = null): array
    {
        $batchSize = $batchSize ?? max(1, (int) config('zedproxy.discounts.expire_batch_size', 500));
        $expired = 0;
        $ordersCleared = 0;
        $errors = 0;

        while (true) {
            // Read a batch of candidate IDs WITHOUT locks; each is then processed
            // in its own transaction locking order → redemption (matches apply's
            // order, so no deadlock with a concurrent apply).
            $ids = DiscountRedemption::expirable()->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                try {
                    DB::transaction(function () use ($id, &$expired, &$ordersCleared) {
                        $peek = DiscountRedemption::whereKey($id)->first();
                        if (! $peek || $peek->status !== DiscountRedemption::STATUS_RESERVED) {
                            return; // already handled by another worker
                        }

                        $order = $peek->order_id
                            ? Order::whereKey($peek->order_id)->lockForUpdate()->first()
                            : null;

                        $row = DiscountRedemption::whereKey($id)
                            ->where('status', DiscountRedemption::STATUS_RESERVED)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now())
                            ->lockForUpdate()
                            ->first();
                        if (! $row) {
                            return; // raced — no longer expirable
                        }

                        $row->update([
                            'status'      => DiscountRedemption::STATUS_EXPIRED,
                            'released_at' => now(),
                        ]);
                        $expired++;

                        // Clear the snapshot of a still-unpaid order so capacity
                        // is truly freed and final_price reverts server-side.
                        if ($order
                            && $order->payment_status !== Order::PAYMENT_PAID
                            && (int) $order->discount_code_id === (int) $row->discount_code_id) {
                            $order->update([
                                'discount_code_id'  => null,
                                'discount_code'     => null,
                                'discount_type'     => null,
                                'discount_value'    => null,
                                'discount_toman'    => 0,
                                'final_price_toman' => (int) $order->price_toman,
                            ]);
                            $ordersCleared++;
                        }
                    });
                } catch (\Throwable $e) {
                    $errors++; // never fail the whole batch on one bad row
                }
            }

            if ($ids->count() < $batchSize) {
                break;
            }
        }

        return ['expired' => $expired, 'orders_cleared' => $ordersCleared, 'errors' => $errors];
    }

    // ── Internal rule + capacity checks ───────────────────────────────────────

    private function resolveCode(string $code): ?DiscountCode
    {
        $trimmed = trim($code);
        return DiscountCode::where('code', strtoupper($trimmed))->first()
            ?? DiscountCode::where('code', $trimmed)->first();
    }

    private function assertOrderEligible(Order $order): void
    {
        if ($order->payment_status === Order::PAYMENT_PAID) {
            throw new \RuntimeException('این سفارش قبلاً پرداخت شده است.');
        }
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED, Order::STATUS_COMPLETED], true)) {
            throw new \RuntimeException('این سفارش لغو یا ناموفق است.');
        }
    }

    /**
     * Every non-capacity rule. Returns a Persian error message, or null if OK.
     */
    private function checkRules(DiscountCode $code, Order $order, User $user): ?string
    {
        if (! $code->is_active) {
            return 'این کد تخفیف غیرفعال است.';
        }
        if (! $code->allowsOrderType($order->order_type)) {
            return 'این کد تخفیف برای این نوع خرید قابل استفاده نیست.';
        }
        if ($code->starts_at && $code->starts_at->isFuture()) {
            return 'این کد هنوز فعال نشده است.';
        }
        if ($code->expires_at && $code->expires_at->isPast()) {
            return 'مهلت استفاده از این کد تمام شده است.';
        }
        if ($code->min_order_amount !== null && (int) $order->price_toman < (int) $code->min_order_amount) {
            return 'حداقل مبلغ سفارش برای این کد تخفیف رعایت نشده است.';
        }
        if (! empty($code->allowed_plan_ids)) {
            $effectivePlanId = $order->plan_id ?? $order->userService?->plan_id;
            if ($effectivePlanId !== null && ! in_array($effectivePlanId, $code->allowed_plan_ids)) {
                return 'این کد برای این پلن قابل استفاده نیست.';
            }
        }
        if ($code->first_purchase_only && $this->userHasPaidBefore($user, $order)) {
            return 'این کد تخفیف فقط برای اولین خرید قابل استفاده است.';
        }
        if ($code->new_users_only && $this->userHasPaidBefore($user, $order)) {
            return 'این کد تخفیف فقط برای کاربران جدید قابل استفاده است.';
        }

        return null;
    }

    /**
     * Capacity rules. `used` + non-expired `reserved` consume capacity; this
     * order's OWN active reservation is excluded (it will be released/replaced).
     * Returns a Persian error, or null if capacity is available.
     */
    private function checkCapacity(DiscountCode $code, User $user, Order $order): ?string
    {
        if ($code->total_usage_limit !== null) {
            $total = DiscountRedemption::where('discount_code_id', $code->id)
                ->consumingCapacity()
                ->where(fn ($q) => $this->excludeOwnOrder($q, $order))
                ->count();
            if ($total >= (int) $code->total_usage_limit) {
                return self::MSG_CAPACITY_FULL;
            }
        }

        $perUser = $code->per_user_usage_limit;
        if ($perUser !== null) {
            $userCount = DiscountRedemption::where('discount_code_id', $code->id)
                ->where('user_id', $user->id)
                ->consumingCapacity()
                ->where(fn ($q) => $this->excludeOwnOrder($q, $order))
                ->count();
            if ($userCount >= (int) $perUser) {
                return self::MSG_PER_USER_LIMIT;
            }
        }

        return null;
    }

    /**
     * Exclude only THIS order's own redemptions from a capacity count, while
     * still counting rows with a NULL order_id (SQL `order_id != X` drops NULLs).
     */
    private function excludeOwnOrder($query, Order $order): void
    {
        if (! $order->id) {
            return;
        }
        $query->whereNull('order_id')->orWhere('order_id', '!=', $order->id);
    }

    private function userHasPaidBefore(User $user, Order $order): bool
    {
        return Order::where('user_id', $user->id)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->where('id', '!=', $order->id)
            ->exists();
    }

    /** Release every active (reserved) redemption of the order. */
    private function releaseActive(Order $order, string $reason): int
    {
        return DiscountRedemption::where('order_id', $order->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)
            ->update([
                'status'      => DiscountRedemption::STATUS_RELEASED,
                'released_at' => now(),
            ]);
    }

    private function invalid(string $message): array
    {
        return ['valid' => false, 'message' => $message, 'discount_code' => null, 'discount_amount' => 0];
    }
}
