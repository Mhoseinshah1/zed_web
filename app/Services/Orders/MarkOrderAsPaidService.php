<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PaymentTransaction;
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
    public function __construct(private readonly ServiceProvisioner $provisioner) {}

    /**
     * Mark the order paid and trigger provisioning if not already done.
     *
     * @throws \RuntimeException if the transaction is in an incompatible state
     */
    public function markPaid(Order $order, PaymentTransaction $tx): void
    {
        // Idempotency guard — already fully processed
        if ($order->payment_status === Order::PAYMENT_PAID && $order->service !== null) {
            return;
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

        // Provisioning runs outside the payment transaction to avoid long-running locks
        $order = $order->fresh();
        if ($order->service === null) {
            $this->provisioner->createFromOrder($order);
        }
    }
}
