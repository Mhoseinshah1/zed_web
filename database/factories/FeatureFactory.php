<?php

namespace Database\Factories;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    public function definition(): array
    {
        $title = fake()->words(2, true);
        $slug  = Str::slug($title);
        return [
            'title'      => $title,
            'slug'       => ($slug ?: 'feature') . '-' . Str::random(4),
            'description' => null,
            'icon'       => fake()->randomElement(['⚡', '🔒', '🌍', '📱', '🎧', null]),
            'is_active'  => true,
            'sort_order' => 0,
        ];
    }
}
