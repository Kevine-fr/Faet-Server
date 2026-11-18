<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;

class GenerateSitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sitemap:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate sitemap for FAET frontend';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sitemap = Sitemap::create();

        // 🔹 Pages statiques du front React
        $staticPages = [
            '/',
            '/promotions',
            '/panier',
            '/paiement',
            '/conditions-générales-d-utilisation',
            '/politique-de-confidentialité',
        ];

        foreach ($staticPages as $page) {
            $sitemap->add(Url::create("https://byfaet.fr{$page}"));
        }

        // 🔹 Produits
        foreach (Product::all() as $product) {
            $sitemap->add(
                Url::create("https://byfaet.fr/produit/{$product->id}")
            );
        }

        // 🔹 Catégories
        foreach (Category::all() as $cat) {
            $sitemap->add(
                Url::create("https://byfaet.fr/categorie/{$cat->name}/{$cat->id}")
            );
        }

        // 🔹 Vendeurs
        foreach (Vendor::all() as $vendor) {
            $sitemap->add(
                Url::create("https://byfaet.fr/vendeur/{$vendor->id}")
            );
        }

        // 🔹 Générer le fichier XML
        $sitemap->writeToFile(storage_path('app/public/sitemap.xml'));

        $this->info('✅ Sitemap generated successfully!');
    }
}
