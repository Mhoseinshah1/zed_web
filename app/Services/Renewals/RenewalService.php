<?php

namespace App\Services\Renewals;

use App\Models\Order;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\WalletTransaction;
use App\Services\Marzban\MarzbanClient;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RenewalService
{
    public function __construct(
        private readonly MarzbanClient $marzban,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Create a renewal order for a user service using an existing active plan.
     *
     * @throws \InvalidArgumentException if the service or plan is ineligible
     */
    public function createRenewalOrder(UserService $service, Plan $plan, User $user): Order
    {
        if (! SiteSetting::get('renewal_enabled', true)) {
            throw new \InvalidArgumentException('تمدید سرویس در حال حاضر غیرفعال است.');
        }

        if ($service->user_id !== $user->id) {
            throw new \InvalidArgumentException('دسترسی مجاز نیست.');
        }

        if ($service->expires_at === null) {
            throw new \InvalidArgumentException('این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        if (! $plan->is_active) {
            throw new \InvalidArgumentException('پلن انتخاب‌شده فعال نیست.');
        }

        if (! ($plan->renewal_enabled ?? true)) {
            throw new \InvalidArgumentException('این پلن برای تمدید سرویس مجاز نیست.');
        }

        $renewalPrice   = $plan->effectiveRenewalPrice();
        $renewalDays    = $plan->effectiveRenewalDays();
        $cashbackAmount = $plan->effectiveCashbackAmount();

        return DB::transaction(function () use ($service, $plan, $renewalPrice, $renewalDays, $cashbackAmount) {
            return Order::create([
                'order_type'              => Order::TYPE_RENEWAL,
                'user_id'                 => $service->user_id,
                'user_service_id'         => $service->id,
                'plan_id'                 => $plan->id,
                'original_plan_id'        => $service->plan_id,
                'plan_name'               => $plan->name,
                'plan_slug'               => $plan->slug,
                'traffic_gb'              => $plan->traffic_gb,
                'duration_days'           => $renewalDays,
                'renewal_days'            => $renewalDays,
                'price_toman'             => $renewalPrice,
                'final_price_toman'       => $renewalPrice,
                'discount_toman'          => 0,
                'renewal_cashback_amount' => $cashbackAmount,
                'renewal_cashback_status' => $cashbackAmount ? 'pending' : null,
                'status'                  => Order::STATUS_AWAITING_PAYMENT,
                'payment_status'          => Order::PAYMENT_UNPAID,
                'notes'                   => "تمدید سرویس {$service->service_number} با پلن {$plan->name}",
            ]);
        });
    }

    /**
     * Calculate the new expiry date.
     * Extends from expires_at if still in future, otherwise from now.
     */
    public function calculateNewExpiry(UserService $service, int $days): Carbon
    {
        $base = ($service->expires_at && $service->expires_at->isFuture())
            ? $service->expires_at->copy()
            : now();

        return $base->addDays($days);
    }

    /**
     * Apply a paid renewal order: update UserService expiry and push to Marzban.
     * Idempotent via renewal_applied_at — safe against duplicate IPN/callbacks.
     */
    public function applyRenewal(Order $order): void
    {
        // Idempotent — already applied
        if ($order->renewal_applied_at !== null) {
            return;
        }

        $service = $order->userService;
        if (! $service) {
            Log::error('RenewalService: userService not found for renewal order', ['order_id' => $order->id]);
            $order->update(['status' => Order::STATUS_RENEWAL_FAILED]);
            return;
        }

        $days      = $order->renewal_days ?? $order->duration_days;
        $newExpiry = $this->calculateNewExpiry($service, $days);

        DB::transaction(function () use ($order, $service, $newExpiry) {
            $order->update([
                'original_expire_at' => $service->expires_at,
                'new_expire_at'      => $newExpiry,
                'renewal_applied_at' => now(),
            ]);

            $service->update([
                'expires_at' => $newExpiry,
                'status'     => UserService::STATUS_ACTIVE,
            ]);
        });

        // Push to Marzban — updates expire only, preserves proxies/links/traffic
        if ($service->remote_username) {
            try {
                $panel  = $service->vpnPanel;
                $client = $panel ? new MarzbanClient($panel) : $this->marzban;
                $client->updateUser($service->remote_username, [
                    'expire' => $newExpiry->timestamp,
                ]);
            } catch (\Exception $e) {
                Log::error('RenewalService: Marzban updateUser failed', [
                    'order_id'        => $order->id,
                    'service_id'      => $service->id,
                    'remote_username' => $service->remote_username,
                    'error'           => $e->getMessage(),
                ]);
                // Payment is already confirmed; mark renewal failed so admin can retry
                $order->update(['status' => Order::STATUS_RENEWAL_FAILED]);
                return;
            }
        }

        $order->update([
            'status'       => Order::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // Credit cashback — idempotent, safe for duplicate calls
        $this->applyCashback($order->fresh());
    }

    /**
     * Credit renewal cashback to the user's wallet if eligible.
     * Safe to call multiple times — uses renewal_cashback_status as guard.
     */
    private function applyCashback(Order $order): void
    {
        if (! $order->renewal_cashback_amount || $order->renewal_cashback_status === 'credited') {
            return;
        }

        // Secondary idempotency guard via wallet transaction existence
        $existing = WalletTransaction::where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_RENEWAL_CASHBACK)
            ->first();

        if ($existing) {
            $order->update(['renewal_cashback_status' => 'credited']);
            return;
        }

        $user = $order->user;
        if (! $user) {
            return;
        }

        try {
            $this->walletService->credit($user, $order->renewal_cashback_amount, WalletTransaction::TYPE_RENEWAL_CASHBACK, [
                'order_id'    => $order->id,
                'description' => 'کش‌بک تمدید سرویس',
            ]);
            $order->update(['renewal_cashback_status' => 'credited']);
        } catch (\Exception $e) {
            Log::error('RenewalService: cashback credit failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
