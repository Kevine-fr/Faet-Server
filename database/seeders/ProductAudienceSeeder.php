<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Audience;

class ProductAudienceSeeder extends Seeder
{
    public function run(): void
    {
        $audiences = Audience::pluck('id')->toArray();

        if (empty($audiences)) {
            $this->command->warn("⚠️ Aucune audience trouvée.");
            return;
        }

        $this->command->info("Création des liaisons produits ↔ audiences...");

        $totalInserted = 0;

        // On limite volontairement à 5000 produits pour éviter de saturer Neon
        Product::take(1000)->chunk(500, function ($products) use ($audiences, &$totalInserted) {
            $insertData = [];

            foreach ($products as $product) {
                $randomAudiences = collect($audiences)
                    ->random(rand(1, min(3, count($audiences))))
                    ->all();

                foreach ($randomAudiences as $audienceId) {
                    $insertData[] = [
                        'products_id'  => $product->id,
                        'audiences_id' => $audienceId,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ];
                    $totalInserted++;
                }
            }

            DB::table('product_audiences')->insert($insertData);
        });

        $this->command->info("🎉 {$totalInserted} liaisons produits-audiences créées !");
    }
}
