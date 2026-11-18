<?php

namespace App\Services;

use App\Models\Product;
use App\Models\productSearch;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductSearchService
{
    /**
     * Recherche avec Scout/Meilisearch
     */
    public function search(string $query, int $limit = 20): Collection
    {
        $query = trim($query);
        
        if (empty($query)) {
            return collect([]);
        }

        // Recherche avec Scout
        $productResults = $this->searchProducts($query, $limit);
        $variantResults = $this->searchVariants($query, $limit);

        // Fusionner et dédupliquer les résultats
        $results = $this->mergeResults($productResults, $variantResults, $limit);
        
        // Tracker les recherches (async)
        $this->trackSearches($results);

        return $results;
    }

    /**
     * Recherche dans les produits
     */
    private function searchProducts(string $query, int $limit): Collection
    {
        $products = Product::search($query)
            ->query(fn ($builder) => $builder->with([
                'category',
                'images' => fn ($q) => $q->where('is_primary', true)
                    ->orWhere('position', 1)
                    ->orderBy('is_primary', 'desc')
                    ->orderBy('position')
                    ->limit(1)
            ]))
            ->take($limit)
            ->get();

        return $products->map(fn ($product) => $this->formatProduct($product));
    }

    /**
     * Recherche dans les variants
     */
    private function searchVariants(string $query, int $limit): Collection
    {
        $variants = ProductVariant::search($query)
            ->query(fn ($builder) => $builder->with([
                'product.category',
                'product.images' => fn ($q) => $q->where('is_primary', true)
                    ->orWhere('position', 1)
                    ->orderBy('is_primary', 'desc')
                    ->orderBy('position')
                    ->limit(1)
            ]))
            ->take($limit)
            ->get();

        return $variants->map(fn ($variant) => $this->formatProductFromVariant($variant));
    }

    /**
     * Fusionne les résultats produits et variants
     */
    private function mergeResults(
        Collection $productResults, 
        Collection $variantResults, 
        int $limit
    ): Collection {
        $combined = $productResults->concat($variantResults);

        $deduplicated = $combined->unique(function ($item) {
            return $item['type'] === 'variant' 
                ? "variant_{$item['variant_id']}" 
                : "product_{$item['id']}";
        });

        return $deduplicated->take($limit)->values();
    }

    /**
     * Formate un produit pour la réponse
     */
    private function formatProduct(Product $product): array
    {
        $minPrice = $product->getMinPrice();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category?->name,
            'category_id' => $product->category_id,
            'price' => $minPrice,
            'original_price' => (float) $product->price,
            'has_variants' => $product->variants()->exists(),
            'stock_quantity' => $product->stock_quantity,
            'image' => $product->images->first()?->path,
            'type' => 'product'
        ];
    }

    /**
     * Formate un variant pour la réponse
     */
    private function formatProductFromVariant(ProductVariant $variant): array
    {
        $product = $variant->product;
        
        return [
            'id' => $product->id,
            'variant_id' => $variant->id,
            'name' => $product->name,
            'sku' => $variant->sku,
            'category' => $product->category?->name,
            'category_id' => $product->category_id,
            'price' => (float) ($variant->price ?? $product->price),
            'original_price' => (float) $product->price,
            'has_variants' => true,
            'variant_details' => [
                'size' => $variant->size,
                'color' => $variant->color,
            ],
            'stock_quantity' => $variant->stock_quantity,
            'image' => $product->images->first()?->path,
            'type' => 'variant'
        ];
    }

    /**
     * Enregistre les recherches avec PostgreSQL upsert
     */
    private function trackSearches(Collection $results): void
    {
        if ($results->isEmpty()) {
            return;
        }

        $productIds = $results->pluck('id')->unique();
        $now = now();

        $data = $productIds->map(function ($productId) use ($now) {
            return [
                'product_id' => $productId,
                'search_count' => 1,
                'last_searched_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        })->toArray();

        // Upsert en bulk (PostgreSQL compatible)
        ProductSearch::upsert(
            $data,
            ['product_id'], // Colonne unique
            [
                'search_count' => DB::raw('product_searches.search_count + 1'),
                'last_searched_at',
                'updated_at'
            ]
        );
    }

    /**
     * Récupère les produits populaires
     */
    public function getPopularProducts(int $limit = 10): Collection
    {
        return Product::with([
                'category',
                'images' => fn ($q) => $q->where('is_primary', true)
                    ->orWhere('position', 1)
                    ->orderBy('is_primary', 'desc')
                    ->orderBy('position')
                    ->limit(1)
            ])
            ->join('product_searches', 'products.id', '=', 'product_searches.product_id')
            ->where('products.is_active', true)
            ->where('products.is_blocked', false)
            ->orderBy('product_searches.search_count', 'desc')
            ->orderBy('product_searches.last_searched_at', 'desc')
            ->select('products.*')
            ->limit($limit)
            ->get()
            ->map(fn ($product) => $this->formatProduct($product));
    }

    /**
     * Recherche avancée avec filtres
     */
    public function advancedSearch(
        string $query,
        ?int $categoryId = null,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        int $limit = 20
    ): Collection {
        $searchQuery = Product::search($query);

        if ($categoryId) {
            $searchQuery->where('category_id', $categoryId);
        }

        if ($minPrice !== null) {
            $searchQuery->where('price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $searchQuery->where('price', '<=', $maxPrice);
        }

        $products = $searchQuery
            ->query(fn ($builder) => $builder->with([
                'category',
                'images' => fn ($q) => $q->where('is_primary', true)
                    ->orWhere('position', 1)
                    ->orderBy('is_primary', 'desc')
                    ->limit(1)
            ]))
            ->take($limit)
            ->get();

        return $products->map(fn ($product) => $this->formatProduct($product));
    }
}