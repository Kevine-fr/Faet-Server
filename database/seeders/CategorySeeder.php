<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // 1️⃣ Audiences
        $audiences = [
            ['name' => 'Femme', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Homme', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Enfant', 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('audiences')->insert($audiences);

        $audienceIds = DB::table('audiences')->pluck('id', 'name');

        // 2️⃣ Catégories principales
        $mainCategories = [
            ['name' => 'Vêtements', 'description' => 'Articles vestimentaires', 'parent_id' => null],
            ['name' => 'Chaussures', 'description' => 'Toutes les chaussures', 'parent_id' => null],
            ['name' => 'Accessoires', 'description' => 'Accessoires de mode', 'parent_id' => null],
        ];

        foreach ($mainCategories as $cat) {
            $cat['created_at'] = $now;
            $cat['updated_at'] = $now;
            DB::table('categories')->insert($cat);
        }

        $categoriesIds = DB::table('categories')->pluck('id', 'name');

        // 3️⃣ Sous-catégories Femme
        $subCategories = [
            // 👗 Vêtements Femme
            ['name' => 'Robes', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Jupes', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Pantalons', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Chemises / Blouses', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'T-shirts / Tops', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Pulls / Gilets', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Vestes / Manteaux', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Lingerie / Sous-vêtements', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Pyjamas / Tenues de maison', 'parent_id' => $categoriesIds['Vêtements']],
            ['name' => 'Tenues de sport', 'parent_id' => $categoriesIds['Vêtements']],

            // 👠 Chaussures Femme
            ['name' => 'Bottes', 'parent_id' => $categoriesIds['Chaussures']],
            ['name' => 'Escarpins', 'parent_id' => $categoriesIds['Chaussures']],
            ['name' => 'Sandales', 'parent_id' => $categoriesIds['Chaussures']],
            ['name' => 'Baskets', 'parent_id' => $categoriesIds['Chaussures']],

            // 💍 Accessoires Femme
            ['name' => 'Sacs', 'parent_id' => $categoriesIds['Accessoires']],
            ['name' => 'Bijoux', 'parent_id' => $categoriesIds['Accessoires']],
            ['name' => 'Ceintures', 'parent_id' => $categoriesIds['Accessoires']],
            ['name' => 'Écharpes / Chapeaux', 'parent_id' => $categoriesIds['Accessoires']],
        ];

        foreach ($subCategories as $sub) {
            $sub['description'] = null;
            $sub['created_at'] = $now;
            $sub['updated_at'] = $now;
            DB::table('categories')->insert($sub);
        }

        // 4️⃣ Association des catégories et audiences (pivot)
        $allCategories = DB::table('categories')->pluck('id', 'name');

        $categoryAudienceData = [];

        // Femme
        foreach ($allCategories as $catName => $catId) {
            $categoryAudienceData[] = [
                'categories_id' => $catId,
                'audiences_id' => $audienceIds['Femme'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Homme (partage certaines catégories)
        $hommeCategories = ['Vêtements', 'Chaussures', 'Accessoires'];
        foreach ($hommeCategories as $cat) {
            $categoryAudienceData[] = [
                'categories_id' => $categoriesIds[$cat],
                'audiences_id' => $audienceIds['Homme'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Enfant
        $enfantCategories = ['Vêtements', 'Chaussures', 'Accessoires'];
        foreach ($enfantCategories as $cat) {
            $categoryAudienceData[] = [
                'categories_id' => $categoriesIds[$cat],
                'audiences_id' => $audienceIds['Enfant'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('category_audiences')->insert($categoryAudienceData);
    }
}
