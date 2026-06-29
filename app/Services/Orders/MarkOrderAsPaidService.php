<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Addons\ServiceAddonService;
use App\Services\Discounts\DiscountService;
use App\Services\Renewals\RenewalService;
use App\Services\ServiceProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Centralized, idempotent service for marking an order as paid
 * and triggering VPN service provisioning.
 *
 * Safe to call multiple times — duplicate IPN/webhooks are no-ops.
 */
class MarkOrderAsPaidService
{
    public function __construct(
        private readonly ServiceProvisioner  $provisioner,
        private readonly DiscountService     $discountService,
        private readonly RenewalService      $renewalService,
        private readonly ServiceAddonService $addonService,
    ) {}

    /**
     * Mark the order paid and trigger provisioning if not already done.
     *
     * @throws \RuntimeException if the transaction is in an incompatible state
     */
    public function markPaid(Order $order, PaymentTransaction $tx): void
    {
        // Idempotency guard — already fully processed
        if ($order->payment_status === Order::PAYMENT_PAID) {
            // Renewal: renewal_applied_at is the authoritative idempotency marker
            if ($order->order_type === Order::TYPE_RENEWAL && $order->renewal_applied_at !== null) {
                return;
            }
            // Add-on (extra traffic/time): addon_applied_at is the idempotency marker
            if ($order->isAddon() && $order->addon_applied_at !== null) {
                return;
            }
            // New-service order already provisioned
            if (! $order->isRenewal() && ! $order->isAddon() && $order->service !== null) {
                return;
            }
        }

        DB::transaction(function () use ($order, $tx) {
            // Refresh inside transaction to prevent race conditions
            $order = $order->fresh();
            $tx    = $tx->fresh();

            if ($order->payment_status !== Order::PAYMENT_PAID) {
                $order->update([
                    'payment_status' => Order::PAYMENT_PAID,
                    'status'         => Order::STATUS_PAID,
                    'paid_at'        => $order->paid_at ?? now(),
                ]);
            }

            if ($tx->status !== PaymentTransaction::STATUS_APPROVED) {
                $tx->update([
                    'status'  => PaymentTransaction::STATUS_APPROVED,
                    'paid_at' => $tx->paid_at ?? now(),
                ]);
            }
        });

        // Mark discount as used — idempotent, safe for duplicate IPN
        $order = $order->fresh();
        $this->discountService->markUsed($order);

        // Notify the buyer that payment succeeded (new-service flow). Idempotent
        // via dedupe key so a duplicate IPN/callback does not re-notify.
        if (! $order->isRenewal() && ! $order->isAddon() && $order->user) {
            app(\App\Services\Notifications\NotificationService::class)->notify(
                \App\Models\Notification::TYPE_PAYMENT_SUCCESS,
                $order->user,
                [
                    'user_name'    => $order->user->name ?? $order->user->username,
                    'order_id'     => $order->order_number,
                    'amount'       => number_format($order->price_toman),
                    'final_amount' => number_format($order->final_price_toman),
                ],
                'payment_success:order:' . $order->id,
            );
        }

        // Route to the correct post-payment handler based on order type
        if ($order->order_type === Order::TYPE_RENEWAL) {
            $this->renewalService->applyRenewal($order);
        } elseif ($order->order_type === Order::TYPE_EXTRA_TRAFFIC) {
            $this->addonService->applyExtraTraffic($order);
        } elseif ($order->order_type === Order::TYPE_EXTRA_TIME) {
            $this->addonService->applyExtraTime($order);
        } elseif ($order->service === null) {
            // New service orders — provisioning runs outside the payment transaction
            $this->provisioner->createFromOrder($order);
        }

        // Representative commission — idempotent (one per order). Never applies
        // to wallet top-ups (those are not Orders routed here).
        app(\App\Services\Referrals\CommissionService::class)->recordForOrder($order->fresh());
    }
}
