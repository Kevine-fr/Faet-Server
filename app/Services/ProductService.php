<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use App\Models\VendorSubscription;
use App\Models\productAudience;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Vendor;
use Carbon\Carbon;
use Exception;

class ProductService
{
    /**
     * Retourne un tableau de catégories avec leurs produits selon les règles suivantes :
     * - Non connecté : tous les produits (aléatoire)
     * - Connecté SANS ville : tous les produits (aléatoire)
     * - Connecté AVEC ville : UNIQUEMENT produits des vendeurs de sa ville
     * - Connecté AVEC ville MAIS aucun vendeur trouvé : retourne tableau vide
     */
    public function getHomeCategoriesWithProducts(?User $user, int $categoriesCount = 5, int $productsPerCategory = 6, ?int $audienceId = null): array
    {
        $userCity = $this->getUserCity($user);

        // Déterminer le mode de filtrage
        $filterMode = $this->determineFilterMode($user, $userCity);

        // Vérifier si des vendeurs existent dans la ville de l'utilisateur
        if ($filterMode === 'city_only') {
            $hasVendorsInCity = $this->checkVendorsInCity($userCity);
            
            if (!$hasVendorsInCity) {
                // Retourner un tableau vide avec un message
                return [
                    'has_vendors' => false,
                    'message' => "Aucun vendeur n'est présent dans votre ville actuellement.",
                    'categories' => []
                ];
            }
        }

        // Cache des catégories top-level avec audience
        $cacheKey = "home_categories_{$categoriesCount}_{$audienceId}";
        $categories = Cache::remember($cacheKey, 1800, function() use ($categoriesCount, $audienceId) {
            $query = Category::whereNull('parent_id');

            if ($audienceId) {
                $query->whereHas('audiences', function ($q) use ($audienceId) {
                    $q->where('audiences_id', $audienceId);
                });
            }

            return $query->inRandomOrder()->take($categoriesCount)->get();
        });

        $result = [];
        $allProductIds = [];

        foreach ($categories as $category) {
            $products = $this->getOptimizedCategoryProducts($category, $productsPerCategory, $userCity, $audienceId, $filterMode);

            if ($products->isNotEmpty()) {
                $allProductIds = array_merge($allProductIds, $products->pluck('id')->toArray());

                $result[] = [
                    'category' => $category,
                    'products' => $products,
                ];
            }
        }

        // UNE SEULE requête pour tous les favoris
        if ($user && !empty($allProductIds)) {
            $favoriteIds = $this->getFavoriteProductIds($user->id, $allProductIds);

            foreach ($result as &$categoryData) {
                foreach ($categoryData['products'] as $product) {
                    $product->setAttribute('is_favorite', in_array($product->id, $favoriteIds, true));
                }
            }
        } else {
            foreach ($result as &$categoryData) {
                foreach ($categoryData['products'] as $product) {
                    $product->setAttribute('is_favorite', false);
                }
            }
        }

        return [
            'has_vendors' => true,
            'filter_mode' => $filterMode,
            'user_city' => $userCity,
            'categories' => $result
        ];
    }

    /**
     * Récupère 6 nouveaux produits (7 derniers jours)
     */
    public function getNewProducts(?User $user, int $limit = 6): array
    {
        $userCity = $this->getUserCity($user);
        $filterMode = $this->determineFilterMode($user, $userCity);

        if ($filterMode === 'city_only') {
            $hasVendorsInCity = $this->checkVendorsInCity($userCity);
            
            if (!$hasVendorsInCity) {
                return [
                    'has_vendors' => false,
                    'message' => "Aucun vendeur n'est présent dans votre ville actuellement.",
                    'products' => []
                ];
            }
        }

        $sevenDaysAgo = Carbon::now()->subDays(7);

        $query = Product::select([
                'products.*',
                DB::raw('(SELECT MIN(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as min_variant_price'),
                DB::raw('(SELECT MAX(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as max_variant_price'),
                DB::raw('(SELECT COUNT(*) FROM product_variants WHERE product_id = products.id AND is_active = true) as variants_count')
            ])
            ->where('products.created_at', '>=', $sevenDaysAgo)
            ->where('is_active', true)
            ->where('is_blocked', false)
            ->with([
                'category:id,name',
                'images' => function($q) {
                    $q->select('id', 'product_id', 'path', 'alt', 'is_primary')
                      ->orderBy('is_primary', 'desc')
                      ->orderBy('position')
                      ->limit(3);
                }
            ]);

        // Filtrage par ville
        if ($filterMode === 'city_only' && $userCity) {
            $query->whereHas('vendor.user', function ($q) use ($userCity) {
                $q->where('city', $userCity);
            });
        }

        $products = $query->inRandomOrder()
            ->limit($limit)
            ->get()
            ->map([$this, 'transformProductData']);
        
        // Gestion des favoris
        if ($user && $products->isNotEmpty()) {
            $productIds = $products->pluck('id')->toArray();
            $favoriteIds = $this->getFavoriteProductIds($user->id, $productIds);

            foreach ($products as $product) {
                $product->setAttribute('is_favorite', in_array($product->id, $favoriteIds, true));
            }
        } else {
            foreach ($products as $product) {
                $product->setAttribute('is_favorite', false);
            }
        }

        return [
            'has_vendors' => true,
            'filter_mode' => $filterMode,
            'user_city' => $userCity,
            'products' => $products
        ];
    }

    /**
     * Récupère 6 produits en promotion
     */
    public function getPromotionProducts(?User $user, int $limit = 6): array
    {
        $userCity = $this->getUserCity($user);
        $filterMode = $this->determineFilterMode($user, $userCity);

        if ($filterMode === 'city_only') {
            $hasVendorsInCity = $this->checkVendorsInCity($userCity);
            
            if (!$hasVendorsInCity) {
                return [
                    'has_vendors' => false,
                    'message' => "Aucun vendeur n'est présent dans votre ville actuellement.",
                    'products' => []
                ];
            }
        }

        $now = Carbon::now();

        // IDs des produits avec promotions actives
        $promotionProductIds = DB::table('promotion_product')
            ->join('promotions', 'promotion_product.promotion_id', '=', 'promotions.id')
            ->where('promotions.is_active', true)
            ->where('promotions.start_date', '<=', $now)
            ->where('promotions.end_date', '>=', $now)
            ->pluck('promotion_product.product_id')
            ->toArray();

        if (empty($promotionProductIds)) {
            return [
                'has_vendors' => true,
                'filter_mode' => $filterMode,
                'user_city' => $userCity,
                'products' => []
            ];
        }

        $query = Product::select([
                'products.*',
                DB::raw('(SELECT MIN(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as min_variant_price'),
                DB::raw('(SELECT MAX(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as max_variant_price'),
                DB::raw('(SELECT COUNT(*) FROM product_variants WHERE product_id = products.id AND is_active = true) as variants_count'),
                // Infos promotion
                DB::raw("(
                    SELECT promotions.discount_value
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_discount"),
                DB::raw("(
                    SELECT promotions.discount_type
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_type"),
                DB::raw("(
                    SELECT promotions.end_date
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_end_date"),
            ])
            ->whereIn('products.id', $promotionProductIds)
            ->where('is_active', true)
            ->where('is_blocked', false)
            ->with([
                'category:id,name',
                'images' => function($q) {
                    $q->select('id', 'product_id', 'path', 'alt', 'is_primary')
                      ->orderBy('is_primary', 'desc')
                      ->orderBy('position')
                      ->limit(3);
                }
            ]);

        // Filtrage par ville
        if ($filterMode === 'city_only' && $userCity) {
            $query->whereHas('vendor.user', function ($q) use ($userCity) {
                $q->where('city', $userCity);
            });
        }

        $products = $query->inRandomOrder()
            ->limit($limit)
            ->get();

        // Calculer les prix avec promotion
        foreach ($products as $product) {
            $this->applyPromotionPricing($product);
        }

        // Gestion des favoris
        if ($user && $products->isNotEmpty()) {
            $productIds = $products->pluck('id')->toArray();
            $favoriteIds = $this->getFavoriteProductIds($user->id, $productIds);

            foreach ($products as $product) {
                $product->setAttribute('is_favorite', in_array($product->id, $favoriteIds, true));
            }
        } else {
            foreach ($products as $product) {
                $product->setAttribute('is_favorite', false);
            }
        }

        return [
            'has_vendors' => true,
            'filter_mode' => $filterMode,
            'user_city' => $userCity,
            'products' => $products
        ];
    }

    /**
     * Applique le calcul de prix avec promotion
     */
    private function applyPromotionPricing($product): void
    {
        $hasPromo = $product->promotion_discount !== null && (float)$product->promotion_discount > 0;

        if (!$hasPromo) {
            // Pas de promo : utiliser transformProductData classique
            $this->transformProductData($product);
            return;
        }

        // Avec variants
        if ($product->min_variant_price !== null) {
            $originalPrice = (float) $product->min_variant_price;
        } 
        // Sans variants
        else if ($product->price !== null) {
            $originalPrice = (float) $product->price;
        } else {
            $originalPrice = 0;
        }

        // Calculer la réduction
        if ($product->promotion_type === 'percentage') {
            $discount = ($originalPrice * (float)$product->promotion_discount) / 100;
        } else {
            $discount = min((float)$product->promotion_discount, $originalPrice);
        }

        $promoPrice = round(max(0, $originalPrice - $discount), 2);

        $product->setAttribute('original_price', $originalPrice);
        $product->setAttribute('promo_price', $promoPrice);
        $product->setAttribute('discount_amount', round($discount, 2));
        $product->setAttribute('discount_percentage', $originalPrice > 0 ? round(($discount / $originalPrice) * 100, 0) : null);
        $product->setAttribute('display_price', $promoPrice);
        $product->setAttribute('price_source', $product->min_variant_price !== null ? 'variants' : 'product');
    }

    /**
     * Détermine le mode de filtrage selon l'état de l'utilisateur
     * 
     * @return string 'all' | 'city_only'
     */
    private function determineFilterMode(?User $user, ?string $userCity): string
    {
        // Non connecté OU connecté sans ville → tous les produits
        if (!$user || !$userCity) {
            return 'all';
        }

        // Connecté avec ville → uniquement sa ville
        return 'city_only';
    }

    /**
     * Vérifie si des vendeurs existent dans une ville donnée
     */
    private function checkVendorsInCity(string $city): bool
    {
        $cacheKey = "vendors_in_city_{$city}";
        
        return Cache::remember($cacheKey, 600, function() use ($city) {
            return DB::table('users')
                ->join('vendors', 'users.id', '=', 'vendors.user_id')
                ->where('users.city', $city)
                ->whereNull('vendors.deleted_at')
                ->exists();
        });
    }

    /**
     * Récupère la ville de l'utilisateur (depuis customer.ville)
     */
    private function getUserCity(?User $user): ?string
    {
        if (!$user || !method_exists($user, 'customer') || !$user->customer) {
            return null;
        }

        return trim($user->customer->ville ?? '') ?: null;
    }

    /**
     * Récupère un produit avec ses relations et ajoute is_favorite si l'utilisateur est connecté.
     *
     * @param User|null $user
     * @param int $productId
     * @return Product
     * @throws ModelNotFoundException
     */
    public function getProductDetails(?User $user, string|int $productId): Product
    {
        // Convertir hash en ID si nécessaire
        $id = is_numeric($productId) ? (int) $productId : app(\App\Services\SqidsService::class)->decode($productId);
        
        if ($id === null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Produit introuvable");
        }

        $now = Carbon::now();

        // Cache du produit
        $cacheKey = "product_details_{$id}";
        $product = Cache::remember($cacheKey, 1800, function () use ($id, $now) {
            return Product::with([
                'category:id,name,parent_id',
                'vendor:id,name_store,user_id',
                'vendor.user:id,street,postal_code,city',
                'audiences:id,name',
                'images' => function ($q) {
                    $q->orderBy('is_primary', 'desc')->orderBy('position');
                },
                'variants' => function ($q) {
                    $q->where('is_active', true);
                }
            ])
                ->where('id', $id)
                ->where('is_active', true)
                ->where('is_blocked', false)
                ->select([
                    'products.*',
                    // Infos promotion
                    DB::raw("(
                    SELECT promotions.discount_value
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_discount"),
                    DB::raw("(
                    SELECT promotions.discount_type
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_type"),
                    DB::raw("(
                    SELECT promotions.end_date
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_end_date"),
                    DB::raw("(
                    SELECT promotions.title
                    FROM promotion_product
                    JOIN promotions ON promotion_product.promotion_id = promotions.id
                    WHERE promotion_product.product_id = products.id
                    AND promotions.is_active = TRUE
                    AND promotions.start_date <= '{$now}'
                    AND promotions.end_date >= '{$now}'
                    LIMIT 1
                ) as promotion_title"),
                    // Infos code promo
                    DB::raw("(
                    SELECT promo_codes.code
                    FROM attach_promo_codes
                    JOIN promo_codes ON attach_promo_codes.promo_code_id = promo_codes.id
                    WHERE attach_promo_codes.product_id = products.id
                    AND promo_codes.is_active = TRUE
                    AND promo_codes.status = 'active'
                    LIMIT 1
                ) as promo_code"),
                    DB::raw("(
                    SELECT promo_codes.name
                    FROM attach_promo_codes
                    JOIN promo_codes ON attach_promo_codes.promo_code_id = promo_codes.id
                    WHERE attach_promo_codes.product_id = products.id
                    AND promo_codes.is_active = TRUE
                    AND promo_codes.status = 'active'
                    LIMIT 1
                ) as promo_code_name"),
                ])
                ->firstOrFail();
        });

        //
        // ===== Calcul des prix / promo (APRÈS le cache) =====
        //
        // Ne traiter la promo QUE si promotion_discount est numérique > 0
        $hasPromo = $product->promotion_discount !== null && (float)$product->promotion_discount > 0;

        if ($hasPromo) {
            // Produit avec prix direct
            if ($product->price !== null) {
                $originalPrice = (float) $product->price;

                if ($product->promotion_type === 'percentage') {
                    $discount = ($originalPrice * (float)$product->promotion_discount) / 100;
                } else {
                    // montant fixe : on ne dépasse pas le prix original
                    $discount = min((float)$product->promotion_discount, $originalPrice);
                }

                $promoPrice = round(max(0, $originalPrice - $discount), 2);

                $product->setAttribute('original_price', $originalPrice);
                $product->setAttribute('promo_price', $promoPrice);
                $product->setAttribute('discount_amount', round($discount, 2));
                $product->setAttribute('discount_percentage', $originalPrice > 0 ? round(($discount / $originalPrice) * 100, 0) : null);
            }
            // Produit avec variants (price null)
            else if ($product->variants && $product->variants->isNotEmpty()) {
                foreach ($product->variants as $variant) {
                    $originalPrice = (float) $variant->price;

                    if ($product->promotion_type === 'percentage') {
                        $discount = ($originalPrice * (float)$product->promotion_discount) / 100;
                    } else {
                        $discount = min((float)$product->promotion_discount, $originalPrice);
                    }

                    $variantPromo = round(max(0, $originalPrice - $discount), 2);

                    $variant->setAttribute('original_price', $originalPrice);
                    $variant->setAttribute('promo_price', $variantPromo);
                    $variant->setAttribute('discount_amount', round($discount, 2));
                    $variant->setAttribute('discount_percentage', $originalPrice > 0 ? round(($discount / $originalPrice) * 100, 0) : null);
                }

                // min original price parmi les variants
                $minOriginal = (float) $product->variants->min('price');

                // min promo price en ignorant les nulls
                $minPromo = $product->variants->pluck('promo_price')->filter()->min();
                $minPromo = $minPromo !== null ? (float) $minPromo : null;

                $product->setAttribute('original_price', $minOriginal);
                $product->setAttribute('promo_price', $minPromo);
            }
        } else {
            // Pas de promotion : promo_price et champs discount = null
            if ($product->price !== null) {
                $product->setAttribute('original_price', (float) $product->price);
                $product->setAttribute('promo_price', null);
                $product->setAttribute('discount_amount', null);
                $product->setAttribute('discount_percentage', null);
            } else if ($product->variants && $product->variants->isNotEmpty()) {
                $minPrice = (float) $product->variants->min('price');
                $product->setAttribute('original_price', $minPrice);
                $product->setAttribute('promo_price', null);
                $product->setAttribute('discount_amount', null);
                $product->setAttribute('discount_percentage', null);

                // s'assurer que chaque variant n'ait pas de promo si pas de promotion
                foreach ($product->variants as $variant) {
                    $variant->setAttribute('promo_price', null);
                    $variant->setAttribute('discount_amount', null);
                    $variant->setAttribute('discount_percentage', null);
                }
            } else {
                // cas très improbable : pas de price et pas de variants
                $product->setAttribute('original_price', null);
                $product->setAttribute('promo_price', null);
                $product->setAttribute('discount_amount', null);
                $product->setAttribute('discount_percentage', null);
            }
        }

        // Données spécifiques à l'utilisateur (pas de cache)
        $isFavorite = false;
        $isFollowing = false;

        if ($user) {
            $isFavorite = DB::table('favorites')
                ->where('user_id', $user->id)
                ->where('product_id', $productId)
                ->whereNull('deleted_at')
                ->exists();

            $vendorId = $product->vendor_id ?? null;
            if ($vendorId) {
                $isFollowing = DB::table('vendor_subscriptions')
                    ->where('user_id', $user->id)
                    ->where('vendor_id', $vendorId)
                    ->whereNull('deleted_at')
                    ->exists();
            }
        }

        $product->setAttribute('is_favorite', (bool) $isFavorite);
        $product->setAttribute('is_following_vendor', (bool) $isFollowing);

        return $product;
    }

    /**
     * Récupère les détails d’un produit par son ID
     *
     * @param int $id
     * @return Product
     *
     * @throws ModelNotFoundException
     */
    public function findById(string|int $id, bool $excludeDeleted = false): Product
    {
        // Convertir hash en ID si nécessaire
        $productId = is_numeric($id) ? (int) $id : app(\App\Services\SqidsService::class)->decode($id);
        
        if ($productId === null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Produit introuvable");
        }

        $query = Product::with([
            'vendor.user',   // vendeur + son user
            'category',      // catégorie
            'images',        // images classées par position
            'variants',      // variantes
            'audiences'      // audiences
        ]);

        // SoftDeletes exclut automatiquement les produits supprimés
        // Si $excludeDeleted est false, on peut utiliser withTrashed() pour inclure les supprimés
        if (!$excludeDeleted) {
            $query->withTrashed();
        }

        return $query->findOrFail($productId);
    }


    /**
     * Récupère des produits similaires par catégorie.
     *
     * @param User|null $user
     * @param int $categoryId
     * @param int $subcategoriesCount
     * @param int $productsPerSubcategory
     * @return array
     */
    public function getSimilarProductsByCategory(?User $user, int $categoryId, int $subcategoriesCount = 2, int $productsPerSubcategory = 6): array
    {
        $userCity = $this->getUserCity($user);
        $filterMode = $this->determineFilterMode($user, $userCity);

        if ($filterMode === 'city_only') {
            $hasVendorsInCity = $this->checkVendorsInCity($userCity);
            
            if (!$hasVendorsInCity) {
                return [
                    'has_vendors' => false,
                    'message' => "Aucun vendeur n'est présent dans votre ville actuellement.",
                    'subcategories' => []
                ];
            }
        }

        // Cache de la catégorie avec ses enfants
        $cacheKey = "category_with_children_{$categoryId}";
        $category = Cache::remember($cacheKey, 3600, function() use ($categoryId) {
            return Category::with('children')->find($categoryId);
        });

        if (!$category) {
            return [
                'has_vendors' => true,
                'filter_mode' => $filterMode,
                'user_city' => $userCity,
                'subcategories' => []
            ];
        }

        $subcategories = $category->children->isEmpty()
            ? collect([$category])
            : $category->children->shuffle()->take($subcategoriesCount);

        // $ville = $this->getUserVille($user);
        $result = [];
        $allProductIds = [];

        foreach ($subcategories as $subcategory) {
            $products = $this->getOptimizedCategoryProducts($subcategory, $productsPerSubcategory, $userCity, null, $filterMode);

            if ($products->isNotEmpty()) {
                $allProductIds = array_merge($allProductIds, $products->pluck('id')->toArray());

                $result[] = [
                    'subcategory' => $subcategory,
                    'products' => $products,
                ];
            }
        }

        // UNE SEULE requête pour tous les favoris
        if ($user && !empty($allProductIds)) {
            $favoriteIds = $this->getFavoriteProductIds($user->id, $allProductIds);

            foreach ($result as &$subcategoryData) {
                foreach ($subcategoryData['products'] as $product) {
                    $product->setAttribute('is_favorite', in_array($product->id, $favoriteIds, true));
                }
            }
        } else {
            foreach ($result as &$subcategoryData) {
                foreach ($subcategoryData['products'] as $product) {
                    $product->setAttribute('is_favorite', false);
                }
            }
        }

        return [
            'has_vendors' => true,
            'filter_mode' => $filterMode,
            'user_city' => $userCity,
            'subcategories' => $result
        ];
    }

    private function getOptimizedCategoryProducts(Category $category, int $limit, ?string $userCity, ?int $audienceId = null, string $filterMode = 'all'): Collection
    {
        $catIds = $this->getCachedCategoryIds($category, $audienceId);

        // Requête de base optimisée
        $baseQuery = Product::select([
                'products.*',
                // Précalculer les prix des variants
                DB::raw('(SELECT MIN(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as min_variant_price'),
                DB::raw('(SELECT MAX(price) FROM product_variants WHERE product_id = products.id AND is_active = true) as max_variant_price'),
                DB::raw('(SELECT COUNT(*) FROM product_variants WHERE product_id = products.id AND is_active = true) as variants_count')
            ])
            ->whereIn('category_id', $catIds)
            ->where('is_active', true)
            ->where('is_blocked', false)
            ->with([
                'category:id,name',
                'images' => function($q) {
                    $q->select('id', 'product_id', 'path', 'alt', 'is_primary')
                      ->orderBy('is_primary', 'desc')
                      ->orderBy('position')
                      ->limit(3); // Limiter les images chargées
                }
            ]);

        // Filtrage par audience si spécifié
        if ($audienceId) {
            $baseQuery->whereHas('audiences', function ($query) use ($audienceId) {
                $query->where('audiences_id', $audienceId);
            });
        }

        // Filtrage par ville selon le mode
        if ($filterMode === 'city_only' && $userCity) {
            // UNIQUEMENT les produits des vendeurs de la ville de l'utilisateur
            $baseQuery->whereHas('vendor.user', function ($q) use ($userCity) {
                $q->where('city', $userCity);
            });
        }

        return $baseQuery
            ->inRandomOrder()
            ->limit($limit)
            ->get()
            ->map([$this, 'transformProductData']);
    }

    public function transformProductData($product): Product
    {
        $variantsCount = (int) ($product->variants_count ?? 0);
        $minPrice = $product->min_variant_price;
        $maxPrice = $product->max_variant_price;

        if ($variantsCount > 0 && $minPrice !== null) {
            $product->setAttribute('display_price', (float) $minPrice);
            $product->setAttribute('price_range', [
                'from' => (float) $minPrice,
                'to' => $maxPrice !== null ? (float) $maxPrice : (float) $minPrice,
            ]);
            $product->setAttribute('price_source', 'variants');
        } else {
            $displayPrice = $product->price !== null ? (float) $product->price : null;
            $product->setAttribute('display_price', $displayPrice);
            $product->setAttribute('price_range', [
                'from' => $displayPrice,
                'to' => $displayPrice,
            ]);
            $product->setAttribute('price_source', 'product');
        }

        return $product;
    }

    private function getCachedCategoryIds(Category $category, ?int $audienceId = null): array
    {
        $cacheKey = "category_descendants_{$category->id}_{$audienceId}";

        return Cache::remember($cacheKey, 3600, function() use ($category, $audienceId) {
            return $this->collectCategoryAndDescendantsIds($category, $audienceId);
        });
    }

    private function getFavoriteProductIds(int $userId, array $productIds): array
    {
        return DB::table('favorites')
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->pluck('product_id')
            ->toArray();
    }

    private function getUserVille(?User $user): ?string
    {
        if (!$user || !method_exists($user, 'customer') || !$user->customer) {
            return null;
        }

        return trim($user->customer->ville ?? '') ?: null;
    }

    protected function collectCategoryAndDescendantsIds(Category $category, ?int $audienceId = null): array
    {
        $ids = [$category->id];
        $queue = [$category->id];

        while (!empty($queue)) {
            $childrenQuery = Category::whereIn('parent_id', $queue);

            if ($audienceId) {
                $childrenQuery->whereHas('audiences', function ($query) use ($audienceId) {
                    $query->where('audiences_id', $audienceId);
                });
            }

            $children = $childrenQuery->pluck('id')->toArray();
            $queue = [];

            foreach ($children as $childId) {
                if (!in_array($childId, $ids)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    public function getPaginated(int $perPage = 50, array $filters = [])
    {
        return Product::with(['category', 'variants'])
            ->categoryId($filters['category_id'] ?? null)
            ->isActive($filters['is_active'] ?? null)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }


   /**
     * Crée un produit avec ses variantes et ses images Cloudinary
     */
    public function createProductWithVariantsAndImages(array $data, array $images = [])
    {
         //  Génération automatique du SKU si non fourni
        if (empty($data['sku'])) {
            $prefix = strtoupper(substr($data['name'], 0, 2)); // 2 premières lettres du nom
            $data['sku'] = $prefix . '-' . strtoupper(Str::random(6));
        }

        //  Création du produit principal
        $product = Product::create([
            'vendor_id'       => $data['vendor_id'],
            'category_id'     => $data['category_id'],
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'sku'             => $data['sku'],
            'price'           => $data['price'] ?? 0,
            'stock_quantity'  => $data['stock_quantity'],
            'external_id'     => $data['external_id'] ?? null,
            'external_data'   => $data['external_data'] ?? null,
        ]);

        //  Upload des images vers Cloudinary
        if (!empty($images) && is_array($images)) {
            foreach ($images as $key => $imageFile) {
                if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
                    try {
                        $uploadResult = Cloudinary::uploadApi()->upload(
                            $imageFile->getRealPath(),
                            [
                                'folder' => 'faet_image_produits',
                                'resource_type' => 'image',
                            ]
                        );

                        $url = $uploadResult['secure_url'] ?? null;

                        ProductImage::create([
                            'product_id' => $product->id,
                            'path'       => $url,
                            'alt'        => $data['name'],
                            'is_primary' => $key === 0,
                            'position'   => $key + 1,
                        ]);
                    } catch (Exception $e) {
                        $product->delete(); // rollback si erreur image
                        throw new Exception("Erreur lors de l'upload de l'image : " . $e->getMessage());
                    }
                }
            }
        }

        //  Gestion des variantes
        if (!empty($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as $variant) {
                // Génère un SKU pour chaque variante si absent
                if (empty($variant['sku'])) {
                    $variant['sku'] = strtoupper(substr($data['name'], 0, 2)) . '-' . strtoupper(Str::random(6));
                }

                $product->variants()->create($variant);
            }
        }

        return $product->load(['images', 'variants']);
    }

    /**
     *  Crée un produit simple a
     */

   public function createSimpleProduct(array $data, array $images = [])
    {
        // 🔹 Récupération du vendor_id
        $vendor = auth()->user()->vendor ?? null;
        if (!$vendor) {
            throw new \Exception("Utilisateur non enregistré comme vendeur.");
        }
        $vendorId = $vendor->id;
        $data['vendor_id'] = $vendorId;

        // 🔹 Générer un SKU unique si manquant
        if (empty($data['sku'])) {
            $productPrefix = strtoupper(substr($data['name'], 0, 2)); // 2 lettres du produit
            $categoryPrefix = strtoupper(substr($data['category_name'] ?? '', 0, 3)); // 3 lettres de la catégorie
            $vendorPrefix = "V" . $vendorId; // ex: V28

            do {
                $randomSuffix = strtoupper(Str::random(8)); // 8 caractères alphanumériques
                $sku = implode('-', array_filter([
                    $productPrefix,
                    $categoryPrefix,
                    $vendorPrefix,
                    $randomSuffix
                ]));
            } while (Product::where('sku', $sku)->exists());

            $data['sku'] = $sku;
        }

        // 🔹 Création du produit
        $product = Product::create([
            'vendor_id'      => $vendorId,
            'category_id'    => $data['category_id'],
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'sku'            => $data['sku'],
            'price'          => $data['price'] ?? 0,
            'stock_quantity' => $data['stock_quantity'] ?? 0,
            'is_active'      => $data['is_active'] ?? true,
            'is_blocked'     => $data['is_blocked'] ?? false,
        ]);

        // 🔹 Associer l'audience si fournie
        if (!empty($data['audience_id'])) {
            try {
                productAudience::create([
                    'products_id' => $product->id,
                    'audiences_id' => $data['audience_id']
                ]);
                Log::info("Association product_audiences créée : products_id={$product->id}, audiences_id={$data['audience_id']}");
            } catch (\Exception $e) {
                Log::error("Erreur lors de la création de l'association product_audiences pour le produit {$product->id}: " . $e->getMessage());
                // Ne pas faire échouer la création du produit si l'association échoue
            }
        }

        // 🔹 Upload des images sur Cloudinary
        if (!empty($images) && is_array($images)) {
            foreach ($images as $key => $imageFile) {
                if ($imageFile instanceof \Illuminate\Http\UploadedFile) {
                    try {
                        $uploadResult = Cloudinary::uploadApi()->upload(
                            $imageFile->getRealPath(),
                            [
                                'folder' => 'faet_image_produits',
                                'resource_type' => 'image',
                                'transformation' => [
                                    [
                                        'quality' => 'auto:good',
                                        'fetch_format' => 'webp',
                                        'width' => 1280,
                                        'crop' => 'limit'
                                    ],
                                ],
                            ]
                        );

                        $url = $uploadResult['secure_url'] ?? null;

                        ProductImage::create([
                            'product_id' => $product->id,
                            'path'       => $url,
                            'alt'        => $data['name'],
                            'is_primary' => $key === 0,
                            'position'   => $key + 1,
                        ]);
                    } catch (\Exception $e) {
                        throw new \Exception("Erreur lors de l'upload Cloudinary : " . $e->getMessage());
                    }
                }
            }
        }

        return $product->load(['images', 'audiences']);
    }

    /**
     * Mettre à jour la catégorie et l'audience de plusieurs produits
     *
     * @param array $productIds Les IDs des produits à mettre à jour
     * @param int|null $categoryId L'ID de la catégorie (peut être null)
     * @param int $audienceId L'ID de l'audience (obligatoire)
     * @param int $vendorId L'ID du vendeur (pour vérifier les permissions)
     * @return array ['updated_count' => int, 'category_id' => int|null, 'audience_id' => int]
     * @throws \Exception
     */
    public function updateMultipleProductsCategoryAndAudience(array $productIds, ?int $categoryId, int $audienceId, int $vendorId): array
    {
        // Trouver tous les produits du vendeur (SoftDeletes exclut automatiquement les supprimés)
        $products = Product::whereIn('id', $productIds)
            ->with('category')
            ->where('vendor_id', $vendorId)
            ->get();

        if ($products->isEmpty()) {
            throw new \Exception('Aucun produit trouvé');
        }

        $updatedCount = 0;

        foreach ($products as $product) {
            // Mettre à jour la catégorie seulement si categoryId est fourni
            if ($categoryId !== null) {
                $oldCategoryId = $product->category_id;
                $product->update(['category_id' => $categoryId]);
                $updatedCount++;

                Log::info("Catégorie du produit {$product->name} (ID: {$product->id}) mise à jour de {$oldCategoryId} vers {$categoryId} par le vendeur {$vendorId}");
            } else {
                // Pas de catégorie à mettre à jour, juste compter pour le message
                $updatedCount++;
            }

            // Audience est obligatoire, créer/mettre à jour l'association dans product_audiences pour chaque produit
            // Un produit ne doit avoir qu'une seule audience à la fois, donc on supprime d'abord les anciennes
            try {
                // Supprimer toutes les anciennes associations d'audience pour ce produit
                productAudience::where('products_id', $product->id)->delete();

                // Créer la nouvelle association avec l'audience sélectionnée
                productAudience::create([
                    'products_id' => $product->id,
                    'audiences_id' => $audienceId
                ]);

                Log::info("Association product_audiences créée/mise à jour : products_id={$product->id}, audiences_id={$audienceId}");
            } catch (\Exception $e) {
                // Logger l'erreur mais ne pas faire échouer toute l'opération
                Log::error("Erreur lors de la création de l'association product_audiences pour le produit {$product->id}: " . $e->getMessage());
                Log::error("Stack trace: " . $e->getTraceAsString());
                // On continue quand même pour les autres produits
            }
        }

        return [
            'updated_count' => $updatedCount,
            'category_id' => $categoryId,
            'audience_id' => $audienceId
        ];
    }


    public function updateSimpleProduct(int $id, array $data, array $images = [])
    {
        // SoftDeletes exclut automatiquement les produits supprimés
        $product = Product::findOrFail($id);

        // Vérifie que le produit appartient bien au vendeur connecté
        $vendorId = Vendor::where('user_id', Auth::id())->value('id');
        if ($product->vendor_id !== $vendorId) {
            throw new Exception("Vous n'avez pas l'autorisation de modifier ce produit.");
        }

        // Met à jour les champs existants
        $product->update(array_filter([
            'category_id'    => $data['category_id'] ?? $product->category_id,
            'name'           => $data['name'] ?? $product->name,
            'description'    => $data['description'] ?? $product->description,
            'sku'            => $data['sku'] ?? $product->sku,
            'price'          => $data['price'] ?? $product->price,
            'stock_quantity' => $data['stock_quantity'] ?? $product->stock_quantity,
            'is_active'      => $data['is_active'] ?? $product->is_active,
            'is_blocked'     => $data['is_blocked'] ?? $product->is_blocked,
        ]));

        // Gérer l'audience si fournie
        if (isset($data['audience_id'])) {
            try {
                // Supprimer toutes les anciennes associations d'audience pour ce produit
                productAudience::where('products_id', $product->id)->delete();

                // Si une nouvelle audience est fournie, créer l'association
                if (!empty($data['audience_id'])) {
                    productAudience::create([
                        'products_id' => $product->id,
                        'audiences_id' => $data['audience_id']
                    ]);
                    Log::info("Association product_audiences mise à jour : products_id={$product->id}, audiences_id={$data['audience_id']}");
                } else {
                    // Si audience_id est vide, on supprime juste l'association (déjà fait ci-dessus)
                    Log::info("Association product_audiences supprimée pour le produit {$product->id}");
                }
            } catch (\Exception $e) {
                Log::error("Erreur lors de la mise à jour de l'association product_audiences pour le produit {$product->id}: " . $e->getMessage());
                // Ne pas faire échouer la mise à jour du produit si l'association échoue
            }
        }

        // AJOUTER de nouvelles images (sans supprimer les anciennes)
        if (!empty($images)) {
            // Récupérer la position maximale actuelle
            $maxPosition = $product->images()->max('position') ?? 0;

            // Vérifier qu'on ne dépasse pas 10 images au total
            $currentImageCount = $product->images()->count();
            $newImageCount = count($images);

            if ($currentImageCount + $newImageCount > 10) {
                throw new Exception("Maximum 10 images par produit. Vous avez déjà {$currentImageCount} images.");
            }

            foreach ($images as $key => $imageFile) {
                $upload = Cloudinary::uploadApi()->upload(
                    $imageFile->getRealPath(),
                    [
                        'folder' => 'faet_image_produits',
                        'transformation' => [
                            ['quality' => 'auto:good', 'fetch_format' => 'webp', 'width' => 1280, 'crop' => 'limit'],
                        ],
                    ]
                );

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => $upload['secure_url'],
                    'alt'        => $product->name,
                    'is_primary' => false, // Les nouvelles images ne sont jamais primaires par défaut
                    'position'   => $maxPosition + $key + 1,
                ]);
            }
        }

        return $product->load(['images', 'audiences']);
    }


     /**
     * Supprimer une image de produit
     *
     * @param int $imageId
     * @return ProductImage
     * @throws Exception
     */
    public function deleteProductImage(int $imageId): ProductImage
    {
        // Trouver l'image
        $image = ProductImage::findOrFail($imageId);

        // Vérifier que l'image appartient à un produit du vendeur connecté
        $vendorId = Vendor::where('user_id', Auth::id())->value('id');

        if ($image->product->vendor_id !== $vendorId) {
            throw new Exception("Vous n'êtes pas autorisé à supprimer cette image.");
        }

        // // Extraire le public_id de Cloudinary depuis l'URL (optionnel)
        // if ($image->path) {
        //     try {
        //         $path = $image->path;
        //         preg_match('/\/v\d+\/(.+)\.(jpg|jpeg|png|gif|webp)$/i', $path, $matches);

        //         if (isset($matches[1])) {
        //             $publicId = $matches[1];

        //             // Supprimer de Cloudinary
        //             Cloudinary::destroy($publicId);
        //         }
        //     } catch (Exception $e) {
        //         // On log l'erreur mais on continue
        //         \Log::warning('Erreur suppression Cloudinary: ' . $e->getMessage());
        //     }
        // }

        // Supprimer de la base de données
        $image->delete();

        return $image;
    }
}
