<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        $direction = fake()->randomElement(['credit', 'debit']);
        $amount    = fake()->numberBetween(50_000, 1_000_000);
        $before    = fake()->numberBetween(0, 5_000_000);

        return [
            'user_id'              => User::factory(),
            'order_id'             => null,
            'payment_transaction_id' => null,
            'type'                 => WalletTransaction::TYPE_TOPUP,
            'direction'            => $direction,
            'amount_toman'         => $amount,
            'balance_before_toman' => $before,
            'balance_after_toman'  => $direction === 'credit' ? $before + $amount : max(0, $before - $amount),
            'status'               => WalletTransaction::STATUS_COMPLETED,
            'description'          => null,
            'admin_id'             => null,
        ];
    }
}
