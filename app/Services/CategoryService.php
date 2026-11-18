<?php

namespace App\Services;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryService
{
    //
     /**
     * Créer une nouvelle catégorie
     */
    public function create(array $data): Category
    {
        return Category::create($data);
    }

    /**
     * Bloquer/Désactiver une catégorie
     */
    public function deactivate(int $id): bool
    {
        $category = Category::findOrFail($id);
        $category->is_active = false;
        return $category->save();
    }

    /**
     * Activer une catégorie
     */
    public function activate(int $id): bool
    {
        $category = Category::findOrFail($id);
        $category->is_active = true;
        return $category->save();
    }

    /**
     *  Récupérer les catégories paginées
     *
     * @param int $perPage
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */

    public function getPaginated(int $perPage = 10, array $filters = [])
    {
        return Category::orderBy('name')
            ->parentOnly($filters['only_parents'] ?? null)   // filtre les catégories principales
            ->childOf($filters['parent_id'] ?? null)         // filtre les sous-catégories
            ->isActive($filters['is_active'] ?? null)        // déjà prévu pour actif/inactif
            ->paginate($perPage);
    }

    /**
     * Récupérer toutes les catégories sans pagination
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAll()
    {
        return Category::orderBy('name')->get();
    }

    /**
     * Récupérer une catégorie par son ID
     *
     * @param int $id
     * @return Category
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \Exception
     */

    public function update(int $id, array $data)
    {
        $category = Category::findOrFail($id);
        $category->update($data);

        return $category;
    }
        /**
         * Récupérer une catégorie par son ID
         */

        public function findById($id)
        {
            return Category::findOrFail($id);
        }

    /**
     * Récupère les catégories racines pour un select
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRootCategoriesForSelect()
    {
        return Category::whereNull('parent_id')
                    ->where('is_active', true)
                    ->orderBy('name', 'asc')
                    ->get(['id', 'name']);
    }

    /**
     * Récupère les produits d'une catégorie avec filtres et pagination
    */
    public function getCategoryProducts(
        Category $category,
        array $filters = [],
        int $perPage = 12,
        $user = null
    ): array {
        $cacheKey = "category_subcategories_{$category->id}";
        $subcategories = Cache::remember($cacheKey, 3600, function() use ($category) {
            return $this->getSubcategories($category);
        });

        // Construire la requête des produits avec optimisations
        $productsQuery = $this->buildOptimizedProductsQuery($category, $filters);

        // Appliquer les filtres de tri
        $this->applySorting($productsQuery, $filters);

        // Paginer les résultats
        $products = $productsQuery->paginate($perPage);

        if($user) {
            $this->addFavoritesInfo($products->getCollection(), $user);
        }

        return [
            'subcategories' => $subcategories,
            'products' => $products,
            'total_count' => $products->total(),
            'filters_applied' => $this->getAppliedFilters($filters),
        ];
    }

    /**
     * Requête optimisée avec moins de jointures et de sous-requêtes
     */
    private function buildOptimizedProductsQuery(Category $category, array $filters): Builder
    {
        $now = Carbon::now();
        
        $query = Product::query()
            ->with([
                'images' => function($q) {
                    $q->select('id', 'product_id', 'path', 'alt', 'is_primary', 'position')
                      ->where('is_primary', true)
                      ->orWhere('position', 1)
                      ->orderBy('is_primary', 'desc')
                      ->orderBy('position');
                },
                'category:id,name',
                'variants' => function($q) {
                    $q->select('id', 'product_id', 'color', 'size', 'price', 'is_active')
                      ->where('is_active', true);
                }
            ])
            ->where('products.is_active', true)
            ->where('products.is_blocked', false);

        // Filtres optimisés
        $this->applyCategoryFilter($query, $category, $filters);
        $this->applyOptimizedVariantFilters($query, $filters);
        $this->applyOptimizedPriceFilter($query, $filters);

        $hasPromotionSubquery = "(
            SELECT COUNT(*)
            FROM promotion_product
            JOIN promotions ON promotion_product.promotion_id = promotions.id
            WHERE promotion_product.product_id = products.id
            AND promotions.is_active = TRUE
            AND promotions.start_date <= '{$now}'
            AND promotions.end_date >= '{$now}'
        ) > 0";

        $hasPromoCodeSubquery = "(
            SELECT COUNT(*)
            FROM attach_promo_codes
            JOIN promo_codes ON attach_promo_codes.promo_code_id = promo_codes.id
            WHERE attach_promo_codes.product_id = products.id
            AND promo_codes.is_active = TRUE
            AND promo_codes.status = 'active'
        ) > 0";

        // Ajouter le SELECT après les filtres WHERE
        $query->select([
            'products.*',
            DB::raw('COALESCE(products.price, (
                SELECT MIN(pv.price)
                FROM product_variants pv
                WHERE pv.product_id = products.id
                AND pv.is_active = TRUE
            )) as effective_price'),
            DB::raw("{$hasPromotionSubquery} as has_promotion"),
            DB::raw("{$hasPromoCodeSubquery} as has_promo_code"),
        ]);

        // IMPORTANT: Toujours trier les promos en premier
        $query->orderByRaw("({$hasPromotionSubquery} OR {$hasPromoCodeSubquery}) DESC");

        return $query;
    }

    /**
     * Filtre de prix optimisé - une seule condition
     */
    private function applyOptimizedPriceFilter(Builder $query, array $filters): void
    {
        if (isset($filters['price_min']) || isset($filters['price_max'])) {
            $query->where(function($subQuery) use ($filters) {
                // Sous-requête pour calculer le prix effectif dans le WHERE
                $priceExpression = DB::raw('COALESCE(products.price, (
                    SELECT MIN(pv.price)
                    FROM product_variants pv
                    WHERE pv.product_id = products.id
                    AND pv.is_active = TRUE
                ))');

                if (isset($filters['price_min']) && isset($filters['price_max'])) {
                    $subQuery->whereBetween($priceExpression, [
                        $filters['price_min'],
                        $filters['price_max']
                    ]);
                } elseif (isset($filters['price_min'])) {
                    $subQuery->whereRaw('COALESCE(products.price, (
                        SELECT MIN(pv.price)
                        FROM product_variants pv
                        WHERE pv.product_id = products.id
                        AND pv.is_active = TRUE
                    )) >= ?', [$filters['price_min']]);
                } elseif (isset($filters['price_max'])) {
                    $subQuery->whereRaw('COALESCE(products.price, (
                        SELECT MIN(pv.price)
                        FROM product_variants pv
                        WHERE pv.product_id = products.id
                        AND pv.is_active = TRUE
                    )) <= ?', [$filters['price_max']]);
                }
            });
        }
    }

    /**
     * Filtres de variantes optimisés - une seule sous-requête
     */
    private function applyOptimizedVariantFilters(Builder $query, array $filters): void
    {
        $hasColorFilter = isset($filters['colors']) && !empty($filters['colors']);
        $hasSizeFilter = isset($filters['sizes']) && !empty($filters['sizes']);

        if ($hasColorFilter || $hasSizeFilter) {
            $query->whereExists(function($subQuery) use ($filters, $hasColorFilter, $hasSizeFilter) {
                $subQuery->select(DB::raw(1))
                         ->from('product_variants')
                         ->whereRaw('product_variants.product_id = products.id')
                         ->where('product_variants.is_active', true);

                if ($hasColorFilter) {
                    $colors = is_array($filters['colors']) ? $filters['colors'] : [$filters['colors']];
                    $subQuery->whereIn('color', $colors);
                }

                if ($hasSizeFilter) {
                    $sizes = is_array($filters['sizes']) ? $filters['sizes'] : [$filters['sizes']];
                    $subQuery->whereIn('size', $sizes);
                }
            });
        }
    }

    /**
     * Méthodes de cache pour les options de filtrage
     */
    public function getAvailableColors(Category $category): array
    {
        $cacheKey = "category_colors_{$category->id}";

        return Cache::remember($cacheKey, 1800, function() use ($category) {
            $categoryIds = $this->getCachedCategoryIds($category);

            return DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->whereIn('products.category_id', $categoryIds)
                ->where('products.is_active', true)
                ->where('products.is_blocked', false)
                ->where('product_variants.is_active', true)
                ->whereNotNull('product_variants.color')
                ->distinct()
                ->pluck('color')
                ->filter()
                ->values()
                ->toArray();
        });
    }

    public function getAvailableSizes(Category $category): array
    {
        $cacheKey = "category_sizes_{$category->id}";

        return Cache::remember($cacheKey, 1800, function() use ($category) {
            $categoryIds = $this->getCachedCategoryIds($category);

            return DB::table('product_variants')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->whereIn('products.category_id', $categoryIds)
                ->where('products.is_active', true)
                ->where('products.is_blocked', false)
                ->where('product_variants.is_active', true)
                ->whereNotNull('product_variants.size')
                ->distinct()
                ->pluck('size')
                ->filter()
                ->values()
                ->toArray();
        });
    }

    public function getPriceRange(Category $category): array
    {
        $cacheKey = "category_price_range_{$category->id}";

        return Cache::remember($cacheKey, 1800, function() use ($category) {
            $categoryIds = $this->getCachedCategoryIds($category);

            // Une seule requête pour les prix
            $priceStats = DB::table('products')
                ->leftJoin('product_variants', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('products.category_id', $categoryIds)
                ->where('products.is_active', true)
                ->where('products.is_blocked', false)
                ->where(function($query) {
                    $query->whereNotNull('products.price')
                          ->orWhere(function($subQuery) {
                              $subQuery->whereNull('products.price')
                                       ->where('product_variants.is_active', true);
                          });
                })
                ->selectRaw('
                    MIN(COALESCE(products.price, product_variants.price)) as min_price,
                    MAX(COALESCE(products.price, product_variants.price)) as max_price
                ')
                ->first();

            return [
                'min' => $priceStats->min_price ?? 0,
                'max' => $priceStats->max_price ?? 0,
            ];
        });
    }

    /**
     * Cache des IDs de catégories
     */
    private function getCachedCategoryIds(Category $category): array
    {
        $cacheKey = "category_ids_{$category->id}";

        return Cache::remember($cacheKey, 3600, function() use ($category) {
            return $this->getAllCategoryIds($category);
        });
    }

    // Reste des méthodes inchangées...
    private function getAllCategoryIds(Category $category): array
    {
        $ids = [$category->id];
        $subcategoryIds = $category->children()->pluck('id')->toArray();
        return array_merge($ids, $subcategoryIds);
    }

    public function getSubcategories(Category $category): Collection
    {
        return $category->children()->where('is_active', true)->get();
    }

    private function applyCategoryFilter(Builder $query, Category $category, array $filters): void
    {
        if (isset($filters['subcategories']) && !empty($filters['subcategories'])) {
            $subcategoryIds = is_array($filters['subcategories'])
                ? $filters['subcategories']
                : [$filters['subcategories']];

            $query->whereIn('category_id', $subcategoryIds);
        } else {
            $categoryIds = $this->getCachedCategoryIds($category);
            $query->whereIn('products.category_id', $categoryIds);
        }
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';

        switch ($sortBy) {
            case 'promotion': // NOUVEAU
            // Déjà géré par orderByRaw plus haut, mais on peut ajouter un tri secondaire
            $query->orderByRaw('LOWER(name) ASC');
            break;
            
            case 'price':
                // Utiliser effective_price calculé dans le SELECT
                $query->orderBy('effective_price', $sortOrder);
                break;

            case 'name':
                // Tri alphabétique insensible à la casse
                $query->orderByRaw('LOWER(name) ' . strtoupper($sortOrder));
                break;

            case 'created_at':
            case 'newest':
            case 'recent':
                // Tri par date de création (plus récent d'abord par défaut)
                $order = in_array($sortBy, ['newest', 'recent']) ? 'desc' : $sortOrder;
                $query->orderBy('products.created_at', $order);
                break;

            case 'oldest':
                // Tri du plus ancien au plus récent
                $query->orderBy('products.created_at', 'asc');
                break;

            case 'updated_at':
            case 'recently_updated':
                // Tri par dernière mise à jour
                $query->orderBy('products.updated_at', 'desc');
                break;

            default:
                // Par défaut : tri alphabétique
                $query->orderByRaw('LOWER(name) ASC');
                break;
        }
    }

    private function getAppliedFilters(array $filters): array
    {
        return [
            'subcategories' => $filters['subcategories'] ?? [],
            'price_min' => $filters['price_min'] ?? null,
            'price_max' => $filters['price_max'] ?? null,
            'colors' => $filters['colors'] ?? [],
            'sizes' => $filters['sizes'] ?? [],
            'sort_by' => $filters['sort_by'] ?? 'name',
            'sort_order' => $filters['sort_order'] ?? 'asc',
        ];
    }

    /**
     * Ajoute les informations de favoris pour une collection de produits
    */
    private function addFavoritesInfo(Collection $products, $user): void
    {
        if (!$user || $products->isEmpty()) {
            return;
        }

        // Récupérer les IDs des produits favoris de l'utilisateur
        $productIds = $products->pluck('id')->toArray();

        $favoriteProductIds = DB::table('favorites')
            ->where('user_id', $user->id)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->pluck('product_id')
            ->toArray();

        // Marquer chaque produit comme favori ou non
        foreach ($products as $product) {
            $product->setAttribute('is_favorite', in_array($product->id, $favoriteProductIds, true));
        }
    }

     /**
     * Crée une catégorie et associe des audiences si elles sont fournies
     *
     * @param array $data
     * @return Category
     */
    public function createWithAudeince(array $data): Category
    {
        // Extraire les audiences si elles existent
        $audienceIds = $data['audience_ids'] ?? [];

        // Supprimer pour éviter problème avec fillable
        unset($data['audience_ids']);

        // Créer la catégorie
        $category = Category::create($data);

        // Associer les audiences si elles existent
        if (!empty($audienceIds)) {
            $category->audiences()->sync($audienceIds);
        }

        // Retourner la catégorie avec les audiences chargées
        return $category->load('audiences');
    }

    /**Update une categorie */
    public function updateCategory($id, array $data)
    {
        $category = Category::findOrFail($id);

        // Met à jour les champs de la catégorie
        $category->update($data);

        // Synchronisation des audiences si fournies
        if (isset($data['audience_ids'])) {
            $category->audiences()->sync($data['audience_ids']);
        }

        return $category->fresh()->load('audiences'); // retourne la catégorie avec ses audiences
    }


    /**
     * Liste toutes les catégories avec parent (nom) et audiences (noms),
     * triées du plus récent au plus ancien.
     */
    public function getAllWithParentAndAudiences(int $perPage = 20, array $filters = [])
    {
        $query = Category::with(['parent:id,name', 'audiences:id,name'])
            ->orderByDesc('created_at'); // tri du plus récent au plus ancien

        // Catégories mères uniquement
        if (!empty($filters['only_parents']) && $filters['only_parents'] == 1) {
            $query->whereNull('parent_id');
        }

        // Sous-catégories uniquement
        if (!empty($filters['only_children']) && $filters['only_children'] == 1) {
            $query->whereNotNull('parent_id');
        }

        // Actives / inactives
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }


}