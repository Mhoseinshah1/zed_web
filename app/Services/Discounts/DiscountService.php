<?php

namespace App\Services\Discounts;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DiscountService
{
    /**
     * Validate a discount code for a user and order.
     *
     * Returns ['valid' => bool, 'message' => string, 'discount_code' => DiscountCode|null, 'discount_amount' => int]
     */
    public function validateCode(User $user, Order $order, string $code): array
    {
        $discountCode = DiscountCode::where('code', strtoupper(trim($code)))->first()
            ?? DiscountCode::where('code', trim($code))->first();

        if (! $discountCode) {
            return $this->invalid('این کد تخفیف معتبر نیست.');
        }

        if (! $discountCode->is_active) {
            return $this->invalid('این کد تخفیف غیرفعال است.');
        }

        // Wallet top-up is never an Order, so order-type restriction only
        // applies to real purchase orders. Discounts never touch top-ups.
        if (! $discountCode->allowsOrderType($order->order_type)) {
            return $this->invalid('این کد تخفیف برای این نوع خرید قابل استفاده نیست.');
        }

        if ($discountCode->starts_at && $discountCode->starts_at->isFuture()) {
            return $this->invalid('این کد هنوز فعال نشده است.');
        }

        if ($discountCode->expires_at && $discountCode->expires_at->isPast()) {
            return $this->invalid('مهلت استفاده از این کد تمام شده است.');
        }

        if ($discountCode->total_usage_limit !== null) {
            $usedCount = DiscountRedemption::where('discount_code_id', $discountCode->id)
                ->where('status', DiscountRedemption::STATUS_USED)
                ->count();
            if ($usedCount >= $discountCode->total_usage_limit) {
                return $this->invalid('سقف استفاده از این کد تخفیف تکمیل شده است.');
            }
        }

        $userUsedCount = DiscountRedemption::where('discount_code_id', $discountCode->id)
            ->where('user_id', $user->id)
            ->where('status', DiscountRedemption::STATUS_USED)
            ->count();
        if ($userUsedCount >= $discountCode->per_user_usage_limit) {
            return $this->invalid('شما قبلاً از این کد تخفیف استفاده کرده‌اید.');
        }

        // Minimum order amount is checked against the (pre-discount) order amount,
        // which is the add-on amount for extra traffic/time orders.
        if ($discountCode->min_order_amount !== null && $order->price_toman < $discountCode->min_order_amount) {
            return $this->invalid('حداقل مبلغ سفارش برای این کد تخفیف رعایت نشده است.');
        }

        // Plan restriction: new-service/renewal orders carry plan_id directly;
        // add-on orders inherit the plan of their target service.
        if (! empty($discountCode->allowed_plan_ids)) {
            $effectivePlanId = $order->plan_id ?? $order->userService?->plan_id;
            if ($effectivePlanId !== null && ! in_array($effectivePlanId, $discountCode->allowed_plan_ids)) {
                return $this->invalid('این کد برای این پلن قابل استفاده نیست.');
            }
        }

        if ($discountCode->first_purchase_only) {
            $hasPaidBefore = Order::where('user_id', $user->id)
                ->where('payment_status', Order::PAYMENT_PAID)
                ->where('id', '!=', $order->id)
                ->exists();
            if ($hasPaidBefore) {
                return $this->invalid('این کد تخفیف فقط برای اولین خرید قابل استفاده است.');
            }
        }

        if ($discountCode->new_users_only) {
            $hasPaidBefore = Order::where('user_id', $user->id)
                ->where('payment_status', Order::PAYMENT_PAID)
                ->where('id', '!=', $order->id)
                ->exists();
            if ($hasPaidBefore) {
                return $this->invalid('این کد تخفیف فقط برای کاربران جدید قابل استفاده است.');
            }
        }

        $discountAmount = $this->calculateDiscount($order, $discountCode);

        return [
            'valid'          => true,
            'message'        => 'کد تخفیف با موفقیت اعمال شد.',
            'discount_code'  => $discountCode,
            'discount_amount'=> $discountAmount,
        ];
    }

    /**
     * Calculate the discount amount in toman for an order.
     */
    public function calculateDiscount(Order $order, DiscountCode $discountCode): int
    {
        if ($discountCode->type === DiscountCode::TYPE_PERCENT) {
            $amount = (int) round($order->price_toman * $discountCode->value / 100);
            if ($discountCode->max_discount_amount !== null) {
                $amount = min($amount, $discountCode->max_discount_amount);
            }
            return min($amount, $order->price_toman);
        }

        // Fixed discount
        return min((int) $discountCode->value, $order->price_toman);
    }

    /**
     * Apply a discount code to an order.
     * Creates a reserved redemption and updates order pricing.
     *
     * @throws \RuntimeException with Persian user-facing message on validation failure
     */
    public function applyToOrder(User $user, Order $order, string $code): Order
    {
        $validation = $this->validateCode($user, $order, $code);

        if (! $validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $discountCode   = $validation['discount_code'];
        $discountAmount = $validation['discount_amount'];

        DB::transaction(function () use ($order, $user, $discountCode, $discountAmount) {
            // Cancel any existing reservation for this order
            DiscountRedemption::where('order_id', $order->id)
                ->where('status', DiscountRedemption::STATUS_RESERVED)
                ->update(['status' => DiscountRedemption::STATUS_CANCELLED]);

            $finalAmount = max(0, $order->price_toman - $discountAmount);

            $order->update([
                'discount_code_id'  => $discountCode->id,
                'discount_code'     => $discountCode->code,
                'discount_type'     => $discountCode->type,
                'discount_value'    => $discountCode->value,
                'discount_toman'    => $discountAmount,
                'final_price_toman' => $finalAmount,
            ]);

            DiscountRedemption::create([
                'discount_code_id' => $discountCode->id,
                'user_id'          => $user->id,
                'order_id'         => $order->id,
                'status'           => DiscountRedemption::STATUS_RESERVED,
                'original_amount'  => $order->price_toman,
                'discount_amount'  => $discountAmount,
                'final_amount'     => $finalAmount,
            ]);
        });

        return $order->fresh();
    }

    /**
     * Remove any applied discount from an order and restore original price.
     */
    public function removeFromOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            DiscountRedemption::where('order_id', $order->id)
                ->where('status', DiscountRedemption::STATUS_RESERVED)
                ->update(['status' => DiscountRedemption::STATUS_CANCELLED]);

            $order->update([
                'discount_code_id'  => null,
                'discount_code'     => null,
                'discount_type'     => null,
                'discount_value'    => null,
                'discount_toman'    => 0,
                'final_price_toman' => $order->price_toman,
            ]);
        });
    }

    /**
     * Mark a discount redemption as used when payment succeeds.
     * Idempotent — safe to call multiple times (duplicate IPN).
     */
    public function markUsed(Order $order): void
    {
        if (! $order->discount_code_id) {
            return;
        }

        // Idempotency: skip if already marked used
        $alreadyUsed = DiscountRedemption::where('order_id', $order->id)
            ->where('status', DiscountRedemption::STATUS_USED)
            ->exists();

        if ($alreadyUsed) {
            return;
        }

        DiscountRedemption::where('order_id', $order->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)
            ->update([
                'status'  => DiscountRedemption::STATUS_USED,
                'used_at' => now(),
            ]);
    }

    /**
     * Release a reserved discount redemption (order cancelled before payment).
     */
    public function releaseReservation(Order $order): void
    {
        DiscountRedemption::where('order_id', $order->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)
            ->update(['status' => DiscountRedemption::STATUS_CANCELLED]);
    }

    private function invalid(string $message): array
    {
        return ['valid' => false, 'message' => $message, 'discount_code' => null, 'discount_amount' => 0];
    }
}
