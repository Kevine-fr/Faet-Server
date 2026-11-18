<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CategoryProduct;
use App\Models\Product;
use App\Models\Category;

class CategoryProductSeeder extends Seeder
{
    /**
     * Exécuter le seeder.
     */
    public function run(): void
    {
        // Récupérer quelques produits et catégories existants
        $products = Product::take(10)->get();
        $categories = Category::take(5)->get();

        if ($products->isEmpty() || $categories->isEmpty()) {
            $this->command->warn('Aucun produit ou catégorie trouvé. Veuillez exécuter ProductSeeder et CategorySeeder en premier.');
            return;
        }

        // Créer des associations aléatoires
        foreach ($products as $product) {
            // Chaque produit aura 1 à 3 catégories
            $randomCategories = $categories->random(rand(1, min(3, $categories->count())));
            
            foreach ($randomCategories as $category) {
                // Vérifier si l'association existe déjà
                $exists = CategoryProduct::where([
                    'product_id' => $product->id,
                    'category_id' => $category->id
                ])->exists();

                if (!$exists) {
                    CategoryProduct::create([
                        'product_id' => $product->id,
                        'category_id' => $category->id,
                    ]);
                }
            }
        }

        $this->command->info('Seeder CategoryProduct exécuté avec succès !');
    }
}
