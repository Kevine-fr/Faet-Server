<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client;

class ConfigureMeilisearch extends Command
{
    protected $signature = 'meilisearch:configure';
    protected $description = 'Configure Meilisearch indexes with proper settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $client = new Client(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key')
            );

            $this->info('🔧 Configuration de l\'index des produits...');
            $this->configureProductsIndex($client);

            $this->info('🔧 Configuration de l\'index des variants...');
            $this->configureVariantsIndex($client);

            $this->info('✅ Configuration terminée avec succès !');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Erreur : ' . $e->getMessage());
            return 1;
        }
    }

    private function configureProductsIndex(Client $client)
    {
        $productsIndex = $client->index('products_index');
        
        $productsIndex->updateSettings([
            // Champs sur lesquels la recherche s'effectue (ordre = priorité)
            'searchableAttributes' => [
                'name',           // Priorité 1 : Nom du produit
                'sku',           // Priorité 2 : SKU
                'category_name', // Priorité 3 : Catégorie
                'variant_skus'   // Priorité 4 : SKUs des variants
            ],
            
            // Champs filtrables
            'filterableAttributes' => [
                'category_id',
                'vendor_id',
                'price',
                'is_active',
                'is_blocked',
                'has_variants'
            ],
            
            // Champs triables
            'sortableAttributes' => [
                'price',
                'created_at',
                'name'
            ],
            
            // Règles de ranking (ordre de pertinence)
            'rankingRules' => [
                'words',        // Nombre de mots correspondants
                'typo',         // Tolérance aux fautes
                'proximity',    // Proximité des mots
                'attribute',    // Position dans searchableAttributes
                'sort',         // Tri personnalisé
                'exactness'     // Correspondance exacte prioritaire
            ],
            
            // Configuration de la tolérance aux fautes de frappe
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,   // Minimum 5 caractères pour 1 faute
                    'twoTypos' => 9   // Minimum 9 caractères pour 2 fautes
                ],
                'disableOnWords' => [],
                'disableOnAttributes' => ['sku'] // Pas de tolérance sur SKU
            ],
            
            // Configuration de la recherche par préfixe
            'prefixSearch' => 'indexingTime', // 'indexingTime' ou 'disabled'
            
            // Affichage des résultats
            'displayedAttributes' => ['*'],
            
            // Pagination
            'pagination' => [
                'maxTotalHits' => 1000
            ]
        ]);

        $this->info('✓ Index produits configuré');
    }

    private function configureVariantsIndex(Client $client)
    {
        $variantsIndex = $client->index('product_variants_index');
        
        $variantsIndex->updateSettings([
            'searchableAttributes' => [
                'product_name',
                'sku',
                'category_name',
                'size',
                'color'
            ],
            
            'filterableAttributes' => [
                'product_id',
                'category_id',
                'price',
                'is_active',
                'size',
                'color'
            ],
            
            'sortableAttributes' => [
                'price'
            ],
            
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness'
            ],
            
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,
                    'twoTypos' => 9
                ],
                'disableOnAttributes' => ['sku']
            ],
            
            'prefixSearch' => 'indexingTime'
        ]);

        $this->info('✓ Index variants configuré');
    }
}
