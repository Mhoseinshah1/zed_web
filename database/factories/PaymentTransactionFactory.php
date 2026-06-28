<?php

namespace Database\Factories;

use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'order_id'        => null,
            'payment_method_id' => null,
            'provider'        => fake()->randomElement(['nowpayments', 'centralpay', 'manual']),
            'method'          => 'gateway',
            'status'          => PaymentTransaction::STATUS_PENDING,
            'amount_toman'    => fake()->numberBetween(100_000, 2_000_000),
            'currency'        => 'IRT',
            'payment_purpose' => 'order_payment',
            'paid_at'         => null,
        ];
    }
}
