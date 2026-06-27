<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'country_name'     => fake()->country(),
            'country_code'     => strtoupper(Str::random(2)),
            'flag_emoji'       => null,
            'description'      => null,
            'is_active'        => true,
            'is_youtube_special' => false,
            'sort_order'       => 0,
        ];
    }
}
