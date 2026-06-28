<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'plan_id'           => null,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
            'plan_name'         => fake()->words(3, true),
            'plan_slug'         => fake()->slug(),
            'traffic_gb'        => fake()->randomElement([10, 20, 50, 100]),
            'duration_days'     => fake()->randomElement([30, 60, 90]),
            'price_toman'       => fake()->numberBetween(100_000, 2_000_000),
            'final_price_toman' => fake()->numberBetween(100_000, 2_000_000),
            'discount_toman'    => 0,
            'currency'          => 'IRT',
            'notes'             => null,
            'admin_notes'       => null,
            'paid_at'           => null,
            'completed_at'      => null,
            'cancelled_at'      => null,
        ];
    }
}
