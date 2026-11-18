<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\ProductVariant;
use Carbon\Carbon;

class OrderTestSeeder extends Seeder
{
   public function run(): void
    {
        $user = User::first() ?? User::factory()->create();
        $variant = ProductVariant::first(); // on prend une variante existante

        if (!$variant) {
            $this->command->warn("⚠️ Aucun ProductVariant trouvé !");
            return;
        }

        $paymentMethods = ['paypal', 'cb', 'stripe'];
        $paymentStatus = 'complete';
        $orderStatuses = ['pending', 'in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned', 'refunded'];

        $order = Order::create([
            'order_number'   => 'CMD-'.rand(10000,99999),
            'user_id'        => $user->id,
            'status'         => 'collected',            // statut valide
            'total_amount'   => 4000000,                // 4 millions €
            'payment_method' => $paymentMethods[array_rand($paymentMethods)],
            'payment_status' => $paymentStatus,
            'created_at'     => Carbon::now(),          // aujourd'hui
        ]);

        OrderItem::create([
            'order_id'          => $order->id,
            'product_id'        => $variant->product_id,
            'product_variant_id'=> $variant->id,
            'quantity'          => 1,
            'unit_price'        => 4000000,
        ]);

        $this->command->info("✅ Une commande de 4 000 000 € créée pour aujourd'hui !");
    }
}
