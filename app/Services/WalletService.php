<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
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
}
