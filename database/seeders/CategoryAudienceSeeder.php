<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoryAudienceSeeder extends Seeder
{
    public function run()
    {
        echo "Création des liaisons catégories ↔ audiences...\n";

        $categories = DB::table('categories')->pluck('id')->toArray();
        $audiences  = DB::table('audiences')->pluck('id')->toArray();

        $insertData = [];
        $batchSize = 1000;
        $totalInserted = 0;

        foreach ($categories as $categoryId) {
            // Récupère 1 ou 2 audiences aléatoires sous forme de tableau
            $randomAudiences = collect($audiences)
                ->random(rand(1, min(2, count($audiences))))
                ->toArray();

            // 🔑 Ici on boucle sur chaque audience individuellement
            foreach ($randomAudiences as $audienceId) {
                $insertData[] = [
                    'categories_id' => $categoryId,
                    'audiences_id'  => $audienceId, // ✅ un seul entier, pas un array
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
                $totalInserted++;

                if (count($insertData) >= $batchSize) {
                    DB::table('category_audiences')->insertOrIgnore($insertData);
                    $insertData = [];
                }
            }
        }

        if (!empty($insertData)) {
            DB::table('category_audiences')->insertOrIgnore($insertData);
        }

        echo "Seeder terminé : {$totalInserted} liaisons insérées.\n";
    }
}
