<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Addons\ServiceAddonService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Renewals\RenewalService;

/**
 * Central, idempotent retry for paid orders whose service operation failed.
 *
 * Routes by order_type to the existing apply services. Each underlying service
 * is already idempotent (active-check / renewal_applied_at / addon_applied_at),
 * so a duplicate retry never applies the same operation twice, never creates a
 * duplicate UserService and never creates a duplicate Marzban user.
 *
 * Payment is never touched here — a Marzban failure keeps the order paid.
 */
class OrderApplyRetryService
{
    public function __construct(
        private readonly ProvisioningService $provisioner,
        private readonly RenewalService      $renewalService,
        private readonly ServiceAddonService $addonService,
    ) {}

    /**
     * Retry applying a paid order. Returns true when the operation is (now) applied.
     *
     * @throws \RuntimeException on unrecoverable provisioning errors (surfaced to admin)
     */
    public function retry(Order $order): bool
    {
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            throw new \RuntimeException('سفارش پرداخت نشده است.');
        }

        $order->update(['last_retry_at' => now()]);

        return match (true) {
            $order->isRenewal() => $this->retryRenewal($order),
            $order->order_type === Order::TYPE_EXTRA_TRAFFIC => $this->retryAddon($order, 'traffic'),
            $order->order_type === Order::TYPE_EXTRA_TIME    => $this->retryAddon($order, 'time'),
            default => $this->retryNewService($order),
        };
    }

    private function retryNewService(Order $order): bool
    {
        // Idempotent: provisionOrder returns early if the service is already active.
        $service = $this->provisioner->provisionOrder($order, forceRetry: true);
        return $service->status === \App\Models\UserService::STATUS_ACTIVE;
    }

    private function retryRenewal(Order $order): bool
    {
        if ($order->renewal_applied_at !== null) {
            return true; // already applied
        }
        $this->renewalService->applyRenewal($order);
        return $order->fresh()->renewal_applied_at !== null;
    }

    private function retryAddon(Order $order, string $kind): bool
    {
        if ($order->addon_applied_at !== null) {
            return true; // already applied
        }

        if ($kind === 'traffic') {
            $this->addonService->applyExtraTraffic($order);
        } else {
            $this->addonService->applyExtraTime($order);
        }

        return $order->fresh()->addon_applied_at !== null;
    }
}
