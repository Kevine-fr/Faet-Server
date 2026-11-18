<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // Récupère tous les vendors
        $vendors = DB::table('vendors')->pluck('id')->toArray();

        if (empty($vendors)) {
            $this->command->error('Aucun vendor trouvé. Abandon.');
            return;
        }

        // Choisit 4 vendors aléatoires (ou moins si pas assez)
        if (count($vendors) <= 4) {
            $selectedVendors = $vendors;
        } else {
            shuffle($vendors);
            $selectedVendors = array_slice($vendors, 0, 4);
        }

        // Récupère la liste des product_id déjà dans des promotions
        $productsInPromotions = DB::table('promotion_product')->pluck('product_id')->toArray();

        foreach ($selectedVendors as $vendorId) {
            // Génère un code lisible et unique (ex: V12-20251119-5F3A1B)
            $code = strtoupper("V{$vendorId}-" . $now->format('Ymd') . '-' . Str::random(6));
            $name = "Promo vendor {$vendorId}";

            // Détermine aléatoirement le type et la valeur
            $type = (rand(0,1) === 0) ? 'percentage' : 'fixed';
            $value = $type === 'percentage' ? (float) rand(5, 30) : (float) rand(50, 500);

            // Crée le promo_code
            $promoId = DB::table('promo_codes')->insertGetId([
                'vendor_id' => $vendorId,
                'code' => $code,
                'name' => $name,
                'description' => "Code généré pour vendor #{$vendorId}",
                'type' => $type,
                'value' => $value,
                'min_order_amount' => null,
                'max_uses' => 100,
                'used_count' => 0,
                'status' => 'active',
                'valid_from' => $now,
                'valid_until' => $now->copy()->addMonths(3),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->command->info("Créé code {$code} (id: {$promoId}) pour vendor #{$vendorId} (type: {$type}, value: {$value}).");

            // Récupère les produits du vendor qui ne sont pas déjà en promotions
            $productsQuery = DB::table('products')
                ->where('vendor_id', $vendorId)
                ->where('is_active', true);

            if (!empty($productsInPromotions)) {
                $productsQuery->whereNotIn('id', $productsInPromotions);
            }

            $vendorProducts = $productsQuery->select('id', 'price')->get();

            if ($vendorProducts->isEmpty()) {
                $this->command->warn("Vendor #{$vendorId} n'a pas de produits disponibles hors promotions — aucun produit lié.");
                continue;
            }

            // On attend de lier entre 2 et 6 produits (ou moins si pas assez)
            $take = min(6, max(2, rand(2, min(6, $vendorProducts->count()))));
            $sampleProducts = $vendorProducts->random($take);

            foreach ($sampleProducts as $product) {
                $productPrice = is_null($product->price) ? null : (float) $product->price;

                // Si produit sans prix valide -> chercher variantes
                if (is_null($productPrice) || $productPrice <= 0) {
                    $variants = DB::table('product_variants')
                        ->where('product_id', $product->id)
                        ->where('is_active', true)
                        ->whereNotNull('price')
                        ->select('id', 'price')
                        ->get();

                    if ($variants->isEmpty()) {
                        $this->command->warn("Produit #{$product->id} (vendor {$vendorId}) sans prix ni variantes -> ignoré.");
                        continue;
                    }

                    $takeVar = min(3, max(1, rand(1, min(3, $variants->count()))));
                    $chosenVariants = $variants->random($takeVar);

                    foreach ($chosenVariants as $variant) {
                        $price = (float) $variant->price;
                        if ($price <= 0) {
                            $this->command->warn("Variante #{$variant->id} du produit #{$product->id} sans prix valide -> ignorée.");
                            continue;
                        }

                        $final = ($type === 'percentage') ? $price - ($price * ($value / 100)) : $price - $value;
                        if ($final < 0) $final = 0;

                        DB::table('attach_promo_codes')->insert([
                            'promo_code_id' => $promoId,
                            'product_id' => $product->id,
                            'final_price' => round($final, 2),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $this->command->info("Lien: promo {$code} -> produit #{$product->id} (var #{$variant->id}) final: " . round($final, 2));
                    }

                    continue;
                }

                $price = $productPrice;
                $final = ($type === 'percentage') ? $price - ($price * ($value / 100)) : $price - $value;
                if ($final < 0) $final = 0;

                DB::table('attach_promo_codes')->insert([
                    'promo_code_id' => $promoId,
                    'product_id' => $product->id,
                    'final_price' => round($final, 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->command->info("Lien: promo {$code} -> produit #{$product->id} final: " . round($final, 2));
            } // foreach product
        } // foreach vendor

        $this->command->info('Seed terminé : 4 vendors sélectionnés, 1 code créé par vendor, liés aux produits hors promotions.');
    }
}
