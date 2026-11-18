<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $total = 5000;   // 1 million de produits
        $batchSize = 500; // insertion par lots

        for ($i = 0; $i < $total; $i += $batchSize) {
            $products = [];

            for ($j = 0; $j < $batchSize; $j++) {
                $products[] = [
                    'vendor_id'      => rand(1, 20), // suppose que tu as déjà des vendors
                    'category_id'    => rand(1, 12),  // suppose que tu as déjà des catégories
                    'name'           => fake()->randomElement([
                        'T-shirt', 'Jean', 'Chemise', 'Robe', 'Veste', 'Pull', 'Short'
                    ]),
                    'description'    => fake()->sentence(),
                    'sku'            => strtoupper('SKU-' . Str::random(8)), // toujours unique
                    'price'          => fake()->randomFloat(2, 5, 500),
                    'stock_quantity' => rand(0, 500),
                    'is_active'      => true,
                    'is_blocked'     => false,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }

            DB::table('products')->insert($products);

            echo "Inserted " . ($i + $batchSize) . " products...\n";
        }
    }
}