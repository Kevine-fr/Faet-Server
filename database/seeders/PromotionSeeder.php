<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromotionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = DB::table('vendors')->pluck('id')->toArray();

        if (empty($vendors)) {
            $this->command->error('Aucun vendor trouvé. Abandon du seeder.');
            return;
        }

        $now = Carbon::now();

        // Définir plusieurs promotions variées
        $promotionDefinitions = [
            [
                'title' => 'Soldes d\'Hiver -30%',
                'discount_type' => 'percentage',
                'discount_value' => 30.00,
                'start_date' => $now->copy()->subDays(5),
                'end_date' => $now->copy()->addDays(25),
                'is_active' => true,
            ],
            [
                'title' => 'Flash Sale -20%',
                'discount_type' => 'percentage',
                'discount_value' => 20.00,
                'start_date' => $now->copy()->subDays(2),
                'end_date' => $now->copy()->addDays(7),
                'is_active' => true,
            ],
            [
                'title' => 'Déstockage -40%',
                'discount_type' => 'percentage',
                'discount_value' => 40.00,
                'start_date' => $now->copy(),
                'end_date' => $now->copy()->addDays(15),
                'is_active' => true,
            ],
            [
                'title' => 'Réduction 100 CFA',
                'discount_type' => 'fixed',
                'discount_value' => 100.00,
                'start_date' => $now->copy()->subDays(3),
                'end_date' => $now->copy()->addDays(20),
                'is_active' => true,
            ],
            [
                'title' => 'Méga Promo -50%',
                'discount_type' => 'percentage',
                'discount_value' => 50.00,
                'start_date' => $now->copy(),
                'end_date' => $now->copy()->addDays(10),
                'is_active' => true,
            ],
            [
                'title' => 'Offre Limitée -15%',
                'discount_type' => 'percentage',
                'discount_value' => 15.00,
                'start_date' => $now->copy()->subDays(1),
                'end_date' => $now->copy()->addDays(30),
                'is_active' => true,
            ],
        ];

        // Récupérer TOUS les produits avec leur prix effectif (product.price ou min variant price)
        $allProducts = DB::table('products')
            ->select([
                'products.id',
                'products.price',
                'products.vendor_id',
                DB::raw('(
                    SELECT MIN(pv.price)
                    FROM product_variants pv
                    WHERE pv.product_id = products.id
                    AND pv.is_active = TRUE
                    AND pv.deleted_at IS NULL
                ) as min_variant_price')
            ])
            ->where('products.is_active', true)
            ->where('products.is_blocked', false)
            ->whereNull('products.deleted_at')
            ->get();

        if ($allProducts->isEmpty()) {
            $this->command->error('Aucun produit actif trouvé. Abandon du seeder.');
            return;
        }

        // Récupérer les IDs des produits déjà liés à un code promo
        $productsWithPromoCode = DB::table('promo_code_product')
            ->pluck('product_id')
            ->toArray();

        // Filtrer pour exclure les produits avec code promo
        $eligibleProducts = $allProducts->filter(function ($product) use ($productsWithPromoCode) {
            return !in_array($product->id, $productsWithPromoCode);
        })->values();

        if ($eligibleProducts->isEmpty()) {
            $this->command->warn('Tous les produits ont déjà un code promo. Aucune promotion créée.');
            return;
        }

        $this->command->info("Produits éligibles pour les promotions : {$eligibleProducts->count()}");

        // Calculer combien de produits on veut en promotion (50% des éligibles)
        $targetCount = (int) ceil($eligibleProducts->count() / 2);
        $this->command->info("Objectif : {$targetCount} produits en promotion");

        // Mélanger et prendre la moitié
        $productsForPromotion = $eligibleProducts->shuffle()->take($targetCount);

        // Grouper les produits par vendor pour équilibrer les promotions
        $productsByVendor = $productsForPromotion->groupBy('vendor_id');

        $totalProductsAssigned = 0;

        foreach ($promotionDefinitions as $promoDef) {
            // Sélectionner un vendor aléatoire parmi ceux qui ont des produits éligibles
            $availableVendors = $productsByVendor->keys()->toArray();
            
            if (empty($availableVendors)) {
                $this->command->warn("Plus de vendors disponibles pour la promotion '{$promoDef['title']}'");
                continue;
            }

            $selectedVendorId = $availableVendors[array_rand($availableVendors)];

            // Créer la promotion
            $promotionId = DB::table('promotions')->insertGetId([
                'vendor_id' => $selectedVendorId,
                'title' => $promoDef['title'],
                'discount_type' => $promoDef['discount_type'],
                'discount_value' => $promoDef['discount_value'],
                'start_date' => $promoDef['start_date'],
                'end_date' => $promoDef['end_date'],
                'is_active' => $promoDef['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Prendre des produits de ce vendor (entre 3 et 10 produits par promo)
            $vendorProducts = $productsByVendor->get($selectedVendorId);
            
            if (!$vendorProducts || $vendorProducts->isEmpty()) {
                $this->command->warn("Aucun produit disponible pour le vendor {$selectedVendorId}");
                continue;
            }

            $productsToAssign = $vendorProducts->random(min(rand(3, 10), $vendorProducts->count()));

            foreach ($productsToAssign as $product) {
                // Vérifier que le produit a un prix valide
                $effectivePrice = $product->price ?? $product->min_variant_price;
                
                if (!$effectivePrice || $effectivePrice <= 0) {
                    $this->command->warn("Produit {$product->id} sans prix valide, ignoré.");
                    continue;
                }

                // Insérer dans la table pivot promotion_product
                try {
                    DB::table('promotion_product')->insert([
                        'promotion_id' => $promotionId,
                        'product_id' => $product->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $totalProductsAssigned++;

                    // Calculer le prix promo pour info
                    if ($promoDef['discount_type'] === 'percentage') {
                        $promoPrice = $effectivePrice - ($effectivePrice * $promoDef['discount_value'] / 100);
                    } else {
                        $promoPrice = max(0, $effectivePrice - $promoDef['discount_value']);
                    }

                    $this->command->info(
                        "✓ Promotion '{$promoDef['title']}' appliquée sur produit #{$product->id} " .
                        "(prix: {$effectivePrice} CFA → {$promoPrice} CFA)"
                    );

                } catch (\Exception $e) {
                    $this->command->error("Erreur lors de l'insertion du produit {$product->id}: {$e->getMessage()}");
                }

                // Retirer le produit du pool pour éviter de le réutiliser
                $productsByVendor[$selectedVendorId] = $productsByVendor[$selectedVendorId]->filter(function($p) use ($product) {
                    return $p->id !== $product->id;
                });

                // Si plus de produits pour ce vendor, le retirer
                if ($productsByVendor[$selectedVendorId]->isEmpty()) {
                    $productsByVendor->forget($selectedVendorId);
                }
            }
        }

        $this->command->info("═══════════════════════════════════════════════");
        $this->command->info("✓ Seeder terminé avec succès !");
        $this->command->info("═══════════════════════════════════════════════");
        $this->command->info("  Total produits éligibles : {$eligibleProducts->count()}");
        $this->command->info("  Produits exclus (avec code promo) : " . count($productsWithPromoCode));
        $this->command->info("  Objectif (50%) : {$targetCount}");
        $this->command->info("  Produits assignés à une promotion : {$totalProductsAssigned}");
        $this->command->info("  Promotions créées : " . count($promotionDefinitions));
        $this->command->info("═══════════════════════════════════════════════");
    }
}
