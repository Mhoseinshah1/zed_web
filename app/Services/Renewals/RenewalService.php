<?php

namespace App\Services\Renewals;

use App\Models\Order;
use App\Models\RenewalPackage;
use App\Models\UserService;
use App\Services\Marzban\MarzbanClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RenewalService
{
    public function __construct(
        private readonly MarzbanClient $marzban,
    ) {}

    /**
     * Create a renewal order for a user service.
     *
     * @throws \InvalidArgumentException if the service or package is ineligible
     */
    public function createRenewalOrder(UserService $service, RenewalPackage $package): Order
    {
        if ($service->expires_at === null) {
            throw new \InvalidArgumentException('این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        if (! $package->is_active) {
            throw new \InvalidArgumentException('بسته تمدید انتخاب‌شده فعال نیست.');
        }

        if (! $package->isAllowedForPlan($service->plan_id)) {
            throw new \InvalidArgumentException('این بسته تمدید برای پلن این سرویس مجاز نیست.');
        }

        return DB::transaction(function () use ($service, $package) {
            return Order::create([
                'order_type'         => Order::TYPE_RENEWAL,
                'user_id'            => $service->user_id,
                'user_service_id'    => $service->id,
                'renewal_package_id' => $package->id,
                'renewal_days'       => $package->duration_days,
                'plan_id'            => $service->plan_id,
                'plan_name'          => $service->plan_name ?? ($service->plan?->name ?? 'تمدید سرویس'),
                'plan_slug'          => $service->plan?->slug ?? 'renewal',
                'traffic_gb'         => null,
                'duration_days'      => $package->duration_days,
                'price_toman'        => $package->price_toman,
                'final_price_toman'  => $package->price_toman,
                'discount_toman'     => 0,
                'status'             => Order::STATUS_AWAITING_PAYMENT,
                'payment_status'     => Order::PAYMENT_UNPAID,
                'notes'              => "تمدید سرویس {$service->service_number} به مدت {$package->duration_days} روز",
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
                $panel = $service->vpnPanel;
                if ($panel) {
                    $client = new MarzbanClient($panel);
                } else {
                    $client = $this->marzban;
                }
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
    }
}
