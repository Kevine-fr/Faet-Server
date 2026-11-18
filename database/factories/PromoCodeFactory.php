<?php

namespace Database\Factories;

use App\Models\PromoCode;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['percentage', 'fixed']);
        $value = $type === 'percentage' ? $this->faker->numberBetween(5, 50) : $this->faker->numberBetween(5, 100);
        
        return [
            'vendor_id' => Vendor::factory(),
            'code' => strtoupper($this->faker->bothify('PROMO##??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'type' => $type,
            'value' => $value,
            'min_order_amount' => $this->faker->optional()->randomFloat(2, 20, 200),
            'max_uses' => $this->faker->optional()->numberBetween(10, 1000),
            'used_count' => $this->faker->numberBetween(0, 50),
            'status' => $this->faker->randomElement(['active', 'upcoming', 'expired', 'inactive']),
            'valid_from' => $this->faker->optional()->dateTimeBetween('-1 month', '+1 month'),
            'valid_until' => $this->faker->optional()->dateTimeBetween('+1 month', '+3 months'),
            'is_active' => $this->faker->boolean(80),
        ];
    }

    /**
     * Code promo actif
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'is_active' => true,
            'valid_from' => now()->subDays(rand(1, 30)),
            'valid_until' => now()->addDays(rand(1, 90)),
        ]);
    }

    /**
     * Code promo à venir
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'upcoming',
            'is_active' => true,
            'valid_from' => now()->addDays(rand(1, 30)),
            'valid_until' => now()->addDays(rand(31, 120)),
        ]);
    }

    /**
     * Code promo expiré
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'is_active' => false,
            'valid_from' => now()->subDays(rand(31, 90)),
            'valid_until' => now()->subDays(rand(1, 30)),
        ]);
    }

    /**
     * Code promo inactif
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'is_active' => false,
        ]);
    }

    /**
     * Code promo avec pourcentage
     */
    public function percentage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'percentage',
            'value' => $this->faker->numberBetween(5, 50),
        ]);
    }

    /**
     * Code promo avec montant fixe
     */
    public function fixed(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'fixed',
            'value' => $this->faker->numberBetween(5, 100),
        ]);
    }
}
