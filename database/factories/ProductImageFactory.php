<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'path' => $this->faker->imageUrl(800, 600, 'products'),
            'alt' => $this->faker->optional()->sentence(3),
            'is_primary' => $this->faker->boolean(20), // 20% de chance d'être principale
            'position' => $this->faker->numberBetween(1, 10),
        ];
    }

    /**
     * Indique que l'image est principale
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
            'position' => 1,
        ]);
    }

    /**
     * Indique que l'image n'est pas principale
     */
    public function secondary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => false,
        ]);
    }
}
