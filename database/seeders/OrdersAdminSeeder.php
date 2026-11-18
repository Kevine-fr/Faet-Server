<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrdersAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create();

        // Charger les utilisateurs et produits
        $users = User::take(100)->get();
        $products = Product::take(1000)->get();

        if ($users->isEmpty() || $products->isEmpty()) {
            $this->command->warn("⚠️ Aucun user ou product trouvé, impossible de générer les commandes !");
            return;
        }

        DB::transaction(function () use ($faker, $users, $products) {
            for ($i = 0; $i < 75; $i++) {
                $user = $users->random();

                // Créer la commande avec order_number
                $order = Order::create([
                    'user_id'        => $user->id,
                    'status'         => $faker->randomElement(['pending', 'in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned']),
                    'payment_method' => $faker->randomElement(['paypal', 'cb', 'stripe']),
                    'payment_status' => $faker->randomElement(['pending', 'complete', 'refunded', 'failed']),
                    'total_amount'   => 0, // recalculé après les items
                    'order_number'   => 'ORD-' . strtoupper(Str::random(10)), // <<=== Ajout order_number
                ]);

                $total = 0;

                // Chaque commande a entre 1 et 5 articles
                $itemsCount = $faker->numberBetween(1, 5);

                for ($j = 0; $j < $itemsCount; $j++) {
                    $product = $products->random();

                    // Variante si disponible
                    $variant = $product->variants()->inRandomOrder()->first();

                    $unitPrice = $variant ? $variant->price : $product->price;
                    $quantity = $faker->numberBetween(1, 3);

                    OrderItem::create([
                        'order_id'          => $order->id,
                        'product_id'        => $product->id,
                        'product_variant_id'=> $variant ? $variant->id : null,
                        'quantity'          => $quantity,
                        'unit_price'        => $unitPrice,
                    ]);

                    $total += ($unitPrice * $quantity);
                }

                // Mise à jour du montant total
                $order->update(['total_amount' => $total]);
            }
        });

        $this->command->info("✅ 5000 commandes générées avec order_number !");
    }
}
