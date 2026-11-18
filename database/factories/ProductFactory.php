<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Models\Vendor;


/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
            'vendor_id' => Vendor::inRandomOrder()->first()->id,
            'category_id' => Category::inRandomOrder()->first()->id,
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'sku' => 'SKU-' . fake()->unique()->numberBetween(10000, 99999) . '-' . Str::upper(Str::random(4)),
            'price' => fake()->randomFloat(2, 5, 1000), // entre 5€ et 1000€
            'stock_quantity' => fake()->numberBetween(0, 500),
            'is_active' => fake()->boolean(90), // 90% des produits actifs
            'is_blocked' => fake()->boolean(10), // 10% bloqués

        ];
    }
}
