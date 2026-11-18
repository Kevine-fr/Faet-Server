<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Vendor;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
public function run(): void
    {
        $faker = \Faker\Factory::create();

        $orders  = Order::all();
        $vendors = Vendor::all();

        if ($orders->isEmpty() || $vendors->isEmpty()) {
            $this->command->warn("⚠️ Aucun order ou vendor trouvé, impossible de générer des transactions !");
            return;
        }

        DB::transaction(function () use ($faker, $orders, $vendors) {
            foreach ($orders as $order) {
                // On récupère un vendor aléatoire (dans la vraie logique, ce serait lié aux produits de la commande)
                $vendor = $vendors->random();

                // On génère entre 1 et 2 transactions par commande
                $transactionsCount = $faker->numberBetween(1, 2);

                for ($i = 0; $i < $transactionsCount; $i++) {
                    $type = $faker->randomElement(['payment', 'refund', 'transfer']);

                    // Si refund, on prend une partie du montant
                    $amount = $type === 'refund'
                        ? $faker->randomFloat(2, 1, $order->total_amount / 2)
                        : $order->total_amount;

                    Transaction::create([
                        'order_id'  => $order->id,
                        'vendor_id' => $vendor->id,
                        'type'      => $type,
                        'amount'    => $amount,
                        'status'    => $faker->randomElement(['pending', 'completed', 'failed', 'refunded']),
                    ]);
                }
            }
        });

        $this->command->info("✅ Transactions générées avec succès !");
    }
}