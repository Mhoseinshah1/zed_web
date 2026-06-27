<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public function __construct(private WalletService $wallet) {}

    public function payWithWallet(Order $order, User $user): PaymentTransaction
    {
        return DB::transaction(function () use ($order, $user) {
            if ($order->payment_status === Order::PAYMENT_PAID) {
                throw new RuntimeException('این سفارش قبلاً پرداخت شده است.');
            }

            $method = PaymentMethod::where('type', PaymentMethod::TYPE_WALLET)
                ->where('is_active', true)
                ->first();

            // Debit wallet (also locks user row inside its own transaction)
            $this->wallet->debit($user, $order->final_price_toman, WalletTransaction::TYPE_ORDER_PAYMENT, [
                'order_id'    => $order->id,
                'description' => "پرداخت سفارش {$order->order_number}",
            ]);

            $tx = PaymentTransaction::create([
                'order_id'           => $order->id,
                'user_id'            => $user->id,
                'payment_method_id'  => $method?->id,
                'provider'           => 'wallet',
                'method'             => 'wallet',
                'status'             => PaymentTransaction::STATUS_APPROVED,
                'amount_toman'       => $order->final_price_toman,
                'reviewed_at'        => now(),
                'paid_at'            => now(),
            ]);

            $order->update([
                'payment_status' => Order::PAYMENT_PAID,
                'status'         => Order::STATUS_PAID,
                'paid_at'        => now(),
            ]);

            return $tx;
        });
    }

    public function approveTransaction(PaymentTransaction $tx, int $adminId, ?string $adminNote = null): void
    {
        DB::transaction(function () use ($tx, $adminId, $adminNote) {
            if ($tx->status === PaymentTransaction::STATUS_APPROVED) {
                return; // Idempotent — already approved, skip
            }

            if ($tx->status === PaymentTransaction::STATUS_REJECTED) {
                throw new RuntimeException('پرداخت رد شده را نمی‌توان تایید کرد.');
            }

            $tx->update([
                'status'      => PaymentTransaction::STATUS_APPROVED,
                'admin_note'  => $adminNote,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            $order = $tx->order;

            if ($order->payment_status !== Order::PAYMENT_PAID) {
                $order->update([
                    'payment_status' => Order::PAYMENT_PAID,
                    'status'         => Order::STATUS_PAID,
                    'paid_at'        => now(),
                ]);
            }
        });
    }

    public function rejectTransaction(PaymentTransaction $tx, int $adminId, ?string $adminNote = null): void
    {
        DB::transaction(function () use ($tx, $adminId, $adminNote) {
            if ($tx->status === PaymentTransaction::STATUS_APPROVED) {
                throw new RuntimeException('پرداخت تایید شده را نمی‌توان رد کرد.');
            }

            if ($tx->status === PaymentTransaction::STATUS_REJECTED) {
                return; // Idempotent
            }

            $tx->update([
                'status'      => PaymentTransaction::STATUS_REJECTED,
                'admin_note'  => $adminNote,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'rejected_at' => now(),
            ]);

            $order = $tx->order;

            // Only revert order status if no other approved payment exists
            $hasApproved = $order->paymentTransactions()
                ->where('id', '!=', $tx->id)
                ->where('status', PaymentTransaction::STATUS_APPROVED)
                ->exists();

            if (! $hasApproved) {
                $order->update([
                    'payment_status' => Order::PAYMENT_UNPAID,
                    'status'         => Order::STATUS_PENDING,
                ]);
            }
        });
    }
}
