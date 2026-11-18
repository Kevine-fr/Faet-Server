<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
        $statusOptions = ['active', 'inactive', 'blocked'];

        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->phoneNumber(),
            'profile_picture' => fake()->imageUrl(300, 300, 'people'),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'), // ou null si provider Google
            'provider' => fake()->randomElement(['google', null]),
            'status' => fake()->randomElement($statusOptions),
            'email_verified_at' => now(),
            'password_expires_at' => now()->addMonths(3),
            'session_timeout' => 3600,
            'max_login_attempts' => 3,
            'newsletter_enabled' => fake()->boolean(30), // 30% activés
            'notification_enabled' => fake()->boolean(80), // 80% activés
            'remember_token' => Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
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
}
