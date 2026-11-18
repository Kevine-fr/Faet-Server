<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        // Récupérer les produits qui ont des variantes avec des prix valides
        $productsWithVariants = Product::whereHas('variants', function($query) {
            $query->whereNotNull('price')->where('price', '>', 0);
        })->get();
        
        // Récupérer aussi les produits sans variantes mais avec un prix principal
        $productsWithoutVariants = Product::whereNotNull('price')
            ->where('price', '>', 0)
            ->whereDoesntHave('variants')
            ->get();
        
        // Combiner les deux collections
        $products = $productsWithVariants->merge($productsWithoutVariants);
        
        $customers = User::where('id', '>', 3)->take(4)->get(); // Prendre les customers (pas le vendeur)
        
        // Récupérer un vendor existant
        $vendor = Vendor::first();
        if (!$vendor) {
            $this->command->error('Aucun vendor trouvé. Veuillez d\'abord créer des vendors.');
            return;
        }
        $vendorId = $vendor->id;

        if ($products->isEmpty()) {
            $this->command->error('Aucun produit avec un prix valide trouvé (ni dans products ni dans product_variants). Veuillez d\'abord créer des produits avec des prix.');
            return;
        }

        if ($customers->isEmpty()) {
            $this->command->error('Aucun customer trouvé. Veuillez d\'abord créer des customers.');
            return;
        }

        $this->command->info('Création des commandes...');
        $this->command->info("Produits trouvés : {$products->count()} (avec variantes ou prix principal)");

        $orderStatuses   = ['pending', 'in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned'];
        $paymentMethods  = ['paypal', 'cb', 'stripe'];
        $createdOrders   = 0;
        $createdOrderItems = 0;

        for ($i = 1; $i <= 20; $i++) {
            $customer       = $customers->random();
            $status         = $orderStatuses[array_rand($orderStatuses)];
            $paymentMethod  = $paymentMethods[array_rand($paymentMethods)];
            $paymentStatus  = $this->determinePaymentStatus($status);

            $order = Order::create([
                'user_id' => $customer->id,
                'vendor_id' => $vendorId,
                'order_number' => $this->generateOrderNumber(),
                'status' => $status,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'total_amount'   => 0,
                'created_at'     => now()->subDays(rand(1, 30)),
            ]);

            $itemsCount = rand(1, 3);
            $orderTotal = 0;
            $usedProducts = []; // Pour éviter les doublons dans une même commande

            for ($j = 0; $j < $itemsCount; $j++) {
                // Éviter de sélectionner un produit déjà utilisé dans cette commande
                $availableProducts = $products->whereNotIn('id', $usedProducts);
                
                if ($availableProducts->isEmpty()) {
                    break; // Plus de produits disponibles
                }
                
                $product = $availableProducts->random();
                $usedProducts[] = $product->id; // Marquer le produit comme utilisé
                
                $quantity = rand(1, 3);
                
                // Vérifier si le produit a des variantes
                $productVariants = ProductVariant::where('product_id', $product->id)->get();
                
                if ($productVariants->isNotEmpty()) {
                    // Le produit a des variantes, en choisir une aléatoirement
                    $variant = $productVariants->random();
                    $unitPrice = $variant->price;
                    $productVariantId = $variant->id;
                    
                    // Validation supplémentaire pour s'assurer que le prix est valide
                    if (!$unitPrice || $unitPrice <= 0) {
                        $this->command->warn("Variante {$variant->id} du produit {$product->id} ({$product->name}) a un prix invalide: {$unitPrice}. Ignorée.");
                        continue;
                    }
                } else {
                    // Le produit n'a pas de variantes, utiliser son prix principal
                    $unitPrice = $product->price;
                    $productVariantId = null;
                    
                    // Validation supplémentaire pour s'assurer que le prix est valide
                    if (!$unitPrice || $unitPrice <= 0) {
                        $this->command->warn("Produit {$product->id} ({$product->name}) a un prix invalide: {$unitPrice}. Ignoré.");
                        continue;
                    }
                }
                
                $itemTotal = $quantity * $unitPrice;
                $orderTotal += $itemTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $productVariantId, // Maintenant peut être une variante ou null
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]);

                $createdOrderItems++;
            }

            $order->update(['total_amount' => $orderTotal]);
            $createdOrders++;

            if ($i % 5 == 0) {
                $this->command->info("{$i}/15 commandes créées...");
            }
        }

        $this->command->info("✅ {$createdOrders} commandes créées !");
        $this->command->info("✅ {$createdOrderItems} articles ajoutés !");
        $this->displayOrderStatistics();
    }

    private function determinePaymentStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            'in_preparation' => 'pending',
            'to_be_collected' => 'pending',
            'collected' => 'complete',
            'canceled' => 'refunded',
            'abandoned' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Génère un numéro de commande unique.
     */
    private function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'FAET-' . strtoupper(Str::random(8));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    /**
     * Afficher les statistiques des commandes créées
     */
    private function displayOrderStatistics(): void
    {
        $stats = DB::select("
            SELECT status, COUNT(*) as count, AVG(total_amount) as avg_amount
            FROM orders GROUP BY status
        ");

        foreach ($stats as $stat) {
            $this->command->info("   • {$stat->status}: {$stat->count} commandes (moyenne: " . number_format($stat->avg_amount, 2) . "€)");
        }

        $paymentStats = DB::select("
            SELECT payment_method, COUNT(*) as count
            FROM orders GROUP BY payment_method
        ");

        $this->command->info("   📊 Méthodes de paiement :");
        foreach ($paymentStats as $stat) {
            $this->command->info("     - {$stat->payment_method}: {$stat->count} commandes");
        }
    }
}
