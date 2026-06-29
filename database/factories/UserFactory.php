<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phone = '09' . fake()->unique()->numerify('#########');

        return [
            'name'                 => fake()->name(),
            'username'             => fake()->unique()->userName(),
            'email'                => fake()->unique()->safeEmail(),
            'email_verified_at'    => now(),
            'phone'                => $phone,
            'normalized_phone'     => \App\Support\PhoneNumber::normalize($phone),
            'phone_verified_at'    => now(),
            'profile_completed_at' => now(),
            'password'             => static::$password ??= Hash::make('password'),
            'remember_token'       => Str::random(10),
            'is_admin'             => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** User without a phone number (profile incomplete). */
    public function noPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone'                => null,
            'normalized_phone'     => null,
            'phone_verified_at'    => null,
            'profile_completed_at' => null,
        ]);
    }

    /** User with a phone number that has not been verified yet. */
    public function unverifiedPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }
}
