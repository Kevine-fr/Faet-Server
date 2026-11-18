<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use Carbon\Carbon;

class ProductVariantSeeder extends Seeder
{
    public function run(): void
    {
        $colors = ['Noir','Blanc','Rouge','Bleu','Vert','Marron','Gris','Beige','Kaki','Bordeaux','Jaune'];
        $clothingSizes = ['XS','S','M','L','XL','XXL'];
        $shoeSizes = ['36','37','38','39','40','41','42','43','44','45'];
        $numericSizes = ['30','32','34','36','38','40','42','040'];

        $buffer = [];
        $bufferSize = 500;
        $now = Carbon::now();

        // Parcours des produits de façon déterministe et performante
        Product::query()->chunkById(1000, function ($products) use (
            &$buffer,
            $bufferSize,
            $colors,
            $clothingSizes,
            $shoeSizes,
            $numericSizes,
            $now
        ) {
            foreach ($products as $product) {
                // décider si ce produit aura des variantes (50% chance)
                $hasVariants = (rand(1, 100) <= 50);

                if (! $hasVariants) {
                    // Aucun variant pour ce produit => on laisse son prix (s'il existe)
                    continue;
                }

                // le produit aura des variantes -> définir product.price à null
                DB::table('products')->where('id', $product->id)->update(['price' => null, 'updated_at' => $now]);

                // décider combien de variantes créer
                $variantsCount = rand(1, 4);
                $generated = [];

                for ($i = 0; $i < $variantsCount; $i++) {
                    // choisir pool de tailles
                    $poolSelector = rand(0, 2);
                    if ($poolSelector === 0) {
                        $sizePool = $clothingSizes;
                    } elseif ($poolSelector === 1) {
                        $sizePool = $shoeSizes;
                    } else {
                        $sizePool = $numericSizes;
                    }

                    $size = $sizePool[array_rand($sizePool)];
                    $color = $colors[array_rand($colors)];
                    $key = $size . '|' . $color;

                    // éviter doublons localement
                    $tries = 0;
                    while (isset($generated[$key]) && $tries < 6) {
                        $size = $sizePool[array_rand($sizePool)];
                        $color = $colors[array_rand($colors)];
                        $key = $size . '|' . $color;
                        $tries++;
                    }
                    if (isset($generated[$key])) {
                        continue;
                    }
                    $generated[$key] = true;

                    // basePrice : si product->price présent on s'en sert comme base, sinon base aléatoire
                    $basePrice = $product->price ?? rand(500, 5000);

                    $deltaPercent = rand(-20, 30); // -20% à +30%
                    $price = round($basePrice * (1 + $deltaPercent / 100), 2);
                    if ($price <= 0) $price = $basePrice;

                    $sku = $product->sku ? $product->sku . '-' . strtoupper(Str::random(5)) : 'SKU-' . strtoupper(Str::random(10));

                    $buffer[] = [
                        'product_id' => $product->id,
                        'external_id' => null,
                        'sku' => $sku,
                        'price' => $price,
                        'stock_quantity' => rand(0, 200),
                        'size' => $size,
                        'color' => $color,
                        'weight' => rand(50, 2000) / 100,
                        'attributes' => json_encode(['material' => (rand(0,1) ? 'cotton' : 'polyester')]),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($buffer) >= $bufferSize) {
                        DB::table('product_variants')->insert($buffer);
                        $buffer = [];
                    }
                }
            }
        });

        // Insérer le reste
        if (count($buffer) > 0) {
            DB::table('product_variants')->insert($buffer);
            $buffer = [];
        }

        // -------------------------
        // Post-check : corriger les produits qui ont price IS NULL
        // mais **aucune** variante (pouvant arriver si anciens traitements ont mis price = NULL).
        // On récupère les ids et on met un prix aléatoire par produit (vague 500..5000).
        // -------------------------

        // Récupérer les ids des produits à corriger (price IS NULL et pas de variant)
        $idsToFix = DB::table('products')
            ->whereNull('products.price')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('product_variants')
                      ->whereColumn('product_variants.product_id', 'products.id');
            })
            ->pluck('id')
            ->toArray();

        if (!empty($idsToFix)) {
            // Mettre à jour par lots, génération PHP d'un prix par produit (compatible MySQL/Postgres)
            $chunks = array_chunk($idsToFix, 200);
            foreach ($chunks as $chunk) {
                foreach ($chunk as $id) {
                    $randomPrice = rand(500, 5000); // ou calcule plus sophistiqué si tu veux
                    DB::table('products')->where('id', $id)->update([
                        'price' => $randomPrice,
                        'updated_at' => Carbon::now(),
                    ]);
                }
            }
        }
    }
}
