<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);
        return [
            'name'          => $name,
            'slug'          => Str::slug($name) . '-' . Str::random(4),
            'description'   => fake()->sentence(),
            'traffic_gb'    => fake()->randomElement([30, 50, 100, null]),
            'duration_days' => 30,
            'price_toman'   => fake()->numberBetween(30000, 200000),
            'is_active'     => true,
            'is_featured'   => false,
            'is_economic'   => false,
            'sort_order'    => 0,
            'badge'         => null,
        ];
    }
}
