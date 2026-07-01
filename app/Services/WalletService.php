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

            // wallet_balance_toman is not mass-assignable — set it explicitly.
            $locked->forceFill(['wallet_balance_toman' => $after])->save();

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

            // wallet_balance_toman is not mass-assignable — set it explicitly.
            $locked->forceFill(['wallet_balance_toman' => $after])->save();

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
     *
     * Idempotent: a given PaymentTransaction can credit the wallet EXACTLY once.
     * Three layers guard this:
     *   1. The existence check + credit run inside one transaction, with the
     *      payment-transaction row locked, so two concurrent callers for the same
     *      tx are serialised (the second sees the credit the first committed).
     *   2. A DB UNIQUE constraint on payment_transaction_id is the backstop for
     *      any race the lock can't cover (e.g. separate DB connections).
     *   3. If that unique constraint fires, we catch it and return the existing
     *      credit rather than surfacing a 500 — the outcome is still one credit.
     */
    public function creditFromPaymentTransaction(User $user, PaymentTransaction $tx): WalletTransaction
    {
        $created = false;

        try {
            $walletTx = DB::transaction(function () use ($user, $tx, &$created) {
                // Serialise concurrent crediting of the SAME payment transaction.
                PaymentTransaction::whereKey($tx->id)->lockForUpdate()->first();

                $existing = WalletTransaction::where('payment_transaction_id', $tx->id)->first();
                if ($existing !== null) {
                    return $existing;
                }

                $created = true;

                return $this->credit($user, (int) $tx->amount_toman, WalletTransaction::TYPE_TOPUP, [
                    'payment_transaction_id' => $tx->id,
                    'description'            => 'شارژ کیف پول از طریق درگاه پرداخت',
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Lost a concurrent race — the other writer already credited this tx.
            if ($this->isDuplicatePaymentTransaction($e)) {
                return WalletTransaction::where('payment_transaction_id', $tx->id)->firstOrFail();
            }
            throw $e;
        }

        // Notify only on a genuinely new credit (never re-notify a duplicate).
        if ($created) {
            app(\App\Services\Notifications\NotificationService::class)->notify(
                \App\Models\Notification::TYPE_WALLET_TOPUP_SUCCESS,
                $user,
                [
                    'user_name'     => $user->name ?? $user->username,
                    'wallet_amount' => number_format((int) $tx->amount_toman),
                ],
                'wallet_topup_success:tx:' . $tx->id,
            );
        }

        return $walletTx;
    }

    /** True when the query error is a UNIQUE violation on payment_transaction_id. */
    private function isDuplicatePaymentTransaction(\Illuminate\Database\QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // 23505 = PostgreSQL unique_violation; 23000 = generic integrity (SQLite/MySQL).
        return in_array($sqlState, ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
