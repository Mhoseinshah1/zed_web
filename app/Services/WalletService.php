<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function getBalance(User $user): int
    {
        return (int) $user->wallet_balance_toman;
    }

    public function canPay(User $user, int $amount): bool
    {
        return (int) $user->wallet_balance_toman >= $amount;
    }

    public function credit(User $user, int $amount, string $type, array $extra = []): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $extra) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            $before = $locked->wallet_balance_toman;
            $after  = $before + $amount;

            $locked->update(['wallet_balance_toman' => $after]);

            return WalletTransaction::create(array_merge([
                'user_id'              => $user->id,
                'type'                 => $type,
                'direction'            => WalletTransaction::DIRECTION_CREDIT,
                'amount_toman'         => $amount,
                'balance_before_toman' => $before,
                'balance_after_toman'  => $after,
                'status'               => WalletTransaction::STATUS_COMPLETED,
            ], $extra));
        });
    }

    public function debit(User $user, int $amount, string $type, array $extra = []): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $extra) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();

            if ($locked->wallet_balance_toman < $amount) {
                throw new RuntimeException('موجودی کیف پول کافی نیست.');
            }

            $before = $locked->wallet_balance_toman;
            $after  = $before - $amount;

            $locked->update(['wallet_balance_toman' => $after]);

            return WalletTransaction::create(array_merge([
                'user_id'              => $user->id,
                'type'                 => $type,
                'direction'            => WalletTransaction::DIRECTION_DEBIT,
                'amount_toman'         => $amount,
                'balance_before_toman' => $before,
                'balance_after_toman'  => $after,
                'status'               => WalletTransaction::STATUS_COMPLETED,
            ], $extra));
        });
    }

    public function refund(User $user, int $amount, array $extra = []): WalletTransaction
    {
        return $this->credit($user, $amount, WalletTransaction::TYPE_REFUND, $extra);
    }

    /**
     * Credit wallet from a completed payment transaction.
     * Idempotent: a given PaymentTransaction can only credit the wallet once.
     */
    public function creditFromPaymentTransaction(User $user, PaymentTransaction $tx): WalletTransaction
    {
        $existing = WalletTransaction::where('payment_transaction_id', $tx->id)->first();
        if ($existing) {
            return $existing;
        }

        return $this->credit($user, (int) $tx->amount_toman, WalletTransaction::TYPE_TOPUP, [
            'payment_transaction_id' => $tx->id,
            'description'            => 'شارژ کیف پول از طریق درگاه پرداخت',
        ]);
    }
}
