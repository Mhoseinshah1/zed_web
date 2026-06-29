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
     * Returns the created order (awaiting payment).
     *
     * @throws \InvalidArgumentException if the service cannot be renewed
     */
    public function createRenewalOrder(UserService $service, RenewalPackage $package): Order
    {
        if ($service->expires_at === null) {
            throw new \InvalidArgumentException('این سرویس نامحدود است و نیازی به تمدید ندارد.');
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
     * Calculate the new expiry date for a service after adding $days days.
     */
    public function calculateNewExpiry(UserService $service, int $days): Carbon
    {
        $base = ($service->expires_at && $service->expires_at->isFuture())
            ? $service->expires_at->copy()
            : now();

        return $base->addDays($days);
    }

    /**
     * Apply a paid renewal order: update UserService and push to Marzban.
     * Idempotent — safe to call multiple times.
     */
    public function applyRenewal(Order $order): void
    {
        // Already applied
        if ($order->new_expire_at !== null && $order->status === Order::STATUS_COMPLETED) {
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
            ]);

            $service->update([
                'expires_at' => $newExpiry,
                'status'     => UserService::STATUS_ACTIVE,
            ]);
        });

        // Push to Marzban if the service has a remote username
        if ($service->remote_username) {
            try {
                $this->marzban->updateUser($service->remote_username, [
                    'expire' => $newExpiry->timestamp,
                ]);
            } catch (\Exception $e) {
                Log::error('RenewalService: Marzban updateUser failed', [
                    'order_id'        => $order->id,
                    'service_id'      => $service->id,
                    'remote_username' => $service->remote_username,
                    'error'           => $e->getMessage(),
                ]);
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
