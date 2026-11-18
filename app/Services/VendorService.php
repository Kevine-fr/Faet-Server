<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Http\Resources\UserResource;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class VendorService
{
    /* ================================
    |          GITHUB (TOP)
    |=============================== */

    /**
     * Récupérer tous les Vendeurs paginés
     */

    public function getAllVendor(int $perPage = 20, ?string $status = null)
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', 'vendeur'))
            ->orderByDesc('created_at');

        // Application du filtre sur le statut
        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }


    /**
     * Récupère un vendeur par son identifiant
     * avec les informations de l'utilisateur associé.
    */
    public function findById(int $id)
    {
        return Vendor::with('user')->findOrFail($id);
    }

    /**
     * Récupérer l'ID du rôle "vendeur"
     */
    protected function getVendeurleId(): int
    {
        return Role::where('name', 'vendeur')->firstOrFail()->id;
    }

    /**
     * Créer un nouvel utilisateur Vendeur
     *
     * @throws \Exception
     */
    public function createvendeur(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'phone'      => $data['phone'] ?? null,
                'password'   => Hash::make($data['password']),
                'status'     => 'active',
            ]);

            // Associer le rôle "vendeur"
            $user->roles()->attach($this->getVendeurleId());

            return $user;
        });
    }

    /* ================================
    |        LOCAL (HEAD) — BOTTOM
    |=============================== */

    /**
     * Teste la connexion à une plateforme e-commerce.
     */
    public function testConnection(string $platform, array $config): array
    {
        try {
            switch ($platform) {
                case 'shopify':
                    return $this->testShopifyConnection($config);
                case 'woocommerce':
                    return $this->testWooCommerceConnection($config);
                case 'prestashop':
                    return $this->testPrestaShopConnection($config);
                case 'magento':
                    return $this->testMagentoConnection($config);
                default:
                    return ['success' => false, 'error' => 'Plateforme non supportée'];
            }
        } catch (Exception $e) {
            Log::error('Erreur test connexion', ['platform' => $platform, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erreur lors du test de connexion: '.$e->getMessage()];
        }
    }

    private function testShopifyConnection(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        $apiVersion = $config['api_version'] ?? '2024-01';

        if (empty($shopUrl) || empty($accessToken)) {
            return ['success' => false, 'error' => "URL de boutique et token d'accès requis pour Shopify"];
        }

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/admin/api/{$apiVersion}/products.json");

            if ($response->successful()) {
                $products = $response->json('products', []);
                return ['success' => true, 'message' => 'Connexion Shopify réussie', 'products_count' => count($products)];
            }
            return ['success' => false, 'error' => 'Erreur API Shopify: '.$response->status()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Erreur de connexion Shopify: '.$e->getMessage()];
        }
    }

    private function testWooCommerceConnection(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';

        if (empty($shopUrl) || empty($accessToken)) {
            return ['success' => false, 'error' => "URL de boutique et token d'accès requis pour WooCommerce"];
        }

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/wp-json/wc/v3/products');

            if ($response->successful()) {
                $products = $response->json();
                return ['success' => true, 'message' => 'Connexion WooCommerce réussie', 'products_count' => count($products)];
            }
            return ['success' => false, 'error' => 'Erreur API WooCommerce: '.$response->status()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Erreur de connexion WooCommerce: '.$e->getMessage()];
        }
    }

    private function testPrestaShopConnection(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';

        if (empty($shopUrl) || empty($accessToken)) {
            return ['success' => false, 'error' => "URL de boutique et token d'accès requis pour PrestaShop"];
        }

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/api/products');

            if ($response->successful()) {
                $data = $response->json();
                $products = $data['products'] ?? [];
                return ['success' => true, 'message' => 'Connexion PrestaShop réussie', 'products_count' => count($products)];
            }
            return ['success' => false, 'error' => 'Erreur API PrestaShop: '.$response->status()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Erreur de connexion PrestaShop: '.$e->getMessage()];
        }
    }

    private function testMagentoConnection(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';

        if (empty($shopUrl) || empty($accessToken)) {
            return ['success' => false, 'error' => "URL de boutique et token d'accès requis pour Magento"];
        }

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/rest/V1/products');

            if ($response->successful()) {
                $products = $response->json();
                return ['success' => true, 'message' => 'Connexion Magento réussie', 'products_count' => count($products)];
            }
            return ['success' => false, 'error' => 'Erreur API Magento: '.$response->status()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Erreur de connexion Magento: '.$e->getMessage()];
        }
    }

    /**
     * Sauvegarde la configuration e-commerce d'un vendeur + première sync.
     */
    public function saveEcommerceConfig(int $vendorId, array $config): array
    {
        try {
            Log::info('Début saveEcommerceConfig', [
                'vendor_id' => $vendorId,
                'config_keys' => array_keys($config),
                'platform' => $config['platform'] ?? 'N/A',
                'shop_url' => $config['shop_url'] ?? 'N/A',
                'has_access_token' => !empty($config['access_token']),
                'api_version' => $config['api_version'] ?? 'N/A'
            ]);

            $validation = $this->validateConfig($config);
            if (!$validation['valid']) {
                Log::warning('Validation échouée', ['vendor_id' => $vendorId, 'error' => $validation['error'] ?? 'Inconnu']);
                return $validation;
            }

            // Vérifier l'unicité du website_url
            $shopUrl = $config['shop_url'] ?? null;
            if ($shopUrl) {
                $existingVendor = Vendor::where('website_url', $shopUrl)
                    ->where('id', '!=', $vendorId)
                    ->first();
                
                if ($existingVendor) {
                    Log::warning('URL déjà utilisée', ['vendor_id' => $vendorId, 'shop_url' => $shopUrl]);
                    return ['success' => false, 'error' => 'Cette URL de boutique est déjà utilisée par un autre vendeur'];
                }
            }

            Log::info('Test de connexion en cours', ['vendor_id' => $vendorId, 'platform' => $config['platform']]);
            $testResult = $this->testConnection($config['platform'], $config);
            if (!$testResult['success']) {
                Log::error('Test de connexion échoué', [
                    'vendor_id' => $vendorId,
                    'platform' => $config['platform'],
                    'error' => $testResult['error'] ?? 'Erreur inconnue'
                ]);
                return ['success' => false, 'error' => 'Synchronisation refusée, vérifier vos informations: ' . ($testResult['error'] ?? 'Erreur de connexion')];
            }

            Log::info('Test de connexion réussi', ['vendor_id' => $vendorId]);

            $vendor = Vendor::find($vendorId);
            if (!$vendor) {
                Log::error('Vendeur non trouvé', ['vendor_id' => $vendorId]);
                return ['success' => false, 'error' => 'Vendeur non trouvé'];
            }

            Log::info('Mise à jour du vendeur', ['vendor_id' => $vendorId]);
            $vendor->update([
                'ecommerce_platform' => $config['platform'],
                'website_url' => $shopUrl,
                'name_store' => $config['shop_name'] ?? null,
                'access_token' => $config['access_token'] ?? null,
                'ecommerce_platform_version' => $config['api_version'] ?? null,
                'last_synced_at' => now(),
            ]);

            Log::info('Début synchronisation des produits', ['vendor_id' => $vendorId]);
            $syncResult = $this->syncProducts($vendorId, $config);
            Log::info('Synchronisation terminée', [
                'vendor_id' => $vendorId,
                'products_count' => $syncResult['products_count'] ?? 0,
                'synced_products' => $syncResult['synced_products'] ?? 0
            ]);

            // Chiffrer l'access_token après la synchronisation des produits
            $vendor->encryptAccessToken();

            return [
                'success' => true,
                'message' => 'Configuration sauvegardée avec succès',
                'products_count' => $syncResult['products_count'] ?? 0,
                'synced_products' => $syncResult['synced_products'] ?? 0,
                'webhooks_configured' => $syncResult['webhooks_configured'] ?? false,
            ];
        } catch (Exception $e) {
            Log::error('Erreur sauvegarde config e-commerce', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => 'Erreur lors de la sauvegarde: '.$e->getMessage()];
        }
    }

    private function syncProducts(int $vendorId, array $config): array
    {
        try {
            $products = $this->getProductsFromPlatform($config);
            if (empty($products)) {
                return ['products_count' => 0, 'synced_products' => 0, 'webhooks_configured' => false];
            }

            $syncedCount = $this->saveProductsToDatabase($vendorId, $products);
            $webhooksConfigured = $this->configureWebhooks($config);

            return [
                'products_count' => count($products),
                'synced_products' => $syncedCount,
                'webhooks_configured' => $webhooksConfigured,
            ];
        } catch (Exception $e) {
            Log::error('Erreur synchronisation produits', ['vendor_id' => $vendorId, 'error' => $e->getMessage()]);
            return ['products_count' => 0, 'synced_products' => 0, 'webhooks_configured' => false];
        }
    }

    private function getProductsFromPlatform(array $config): array
    {
        return match ($config['platform'] ?? '') {
            'shopify'     => $this->getShopifyProducts($config),
            'woocommerce' => $this->getWooCommerceProducts($config),
            'prestashop'  => $this->getPrestaShopProducts($config),
            'magento'     => $this->getMagentoProducts($config),
            default       => [],
        };
    }

    private function saveProductsToDatabase(int $vendorId, array $products): int
    {
        $syncedCount = 0;
        $defaultCategoryId = null;

        Log::info('Début sauvegarde produits', [
            'vendor_id' => $vendorId,
            'products_count' => count($products),
            'default_category_id' => $defaultCategoryId,
        ]);

        foreach ($products as $product) {
            try {
                Log::info('Sauvegarde produit', [
                    'external_id' => $product['external_id'] ?? null,
                    'name' => $product['name'] ?? null,
                    'vendor_id' => $vendorId,
                ]);

                $sku = $product['sku'] ?? ('SYNC_'.$product['external_id'].'_'.time());

                // Logique intelligente pour le prix :
                // Si le produit a des variantes, le prix principal = NULL (les prix sont dans les variantes)
                // Sinon, on garde le prix du produit
                $hasVariants = !empty($product['variants']) && is_array($product['variants']) && count($product['variants']) > 0;
                $productPrice = $hasVariants ? null : ($product['price'] ?? 0);

                Log::info('Gestion du prix du produit', [
                    'product_name' => $product['name'],
                    'external_id' => $product['external_id'],
                    'has_variants' => $hasVariants,
                    'variants_count' => $hasVariants ? count($product['variants']) : 0,
                    'original_price' => $product['price'] ?? 'N/A',
                    'final_price' => $productPrice,
                    'price_logic' => $hasVariants ? 'NULL (prix dans variantes)' : 'Prix du produit'
                ]);

                // Vérifier si le produit existe déjà (inclure les supprimés pour la restauration)
                $existingProduct = Product::withTrashed()
                    ->where('vendor_id', $vendorId)
                    ->where('external_id', $product['external_id'])
                    ->first();

                if ($existingProduct) {
                    // Si le produit existe et n'est pas supprimé (soft delete), on ne l'ajoute pas
                    if (!$existingProduct->trashed()) {
                        Log::info('Produit existant non supprimé, ignoré', [
                            'product_id' => $existingProduct->id,
                            'external_id' => $product['external_id'],
                            'name' => $product['name'],
                        ]);
                        continue; // Passer au produit suivant
                    }
                    
                    // Si le produit est supprimé (soft delete), on le restaure et on le met à jour
                    if ($existingProduct->trashed()) {
                        Log::info('Produit supprimé trouvé, restauration et mise à jour', [
                            'product_id' => $existingProduct->id,
                            'external_id' => $product['external_id'],
                            'name' => $product['name'],
                        ]);
                        
                        // Restaurer le produit avec SoftDeletes
                        $existingProduct->restore();
                        
                        $existingProduct->update([
                            'category_id'    => $defaultCategoryId,
                            'name'           => $product['name'],
                            'description'    => strip_tags($product['description'] ?? ''),
                            'price'          => $productPrice,
                            'sku'            => $sku,
                            'stock_quantity' => $product['stock'] ?? 0,
                            'status'         => $product['status'] ?? 'active',
                            'external_data'  => json_encode($product),
                            'last_sync'      => now(),
                            'is_active'      => ($product['status'] ?? 'active') === 'active',
                        ]);
                        
                        $savedProduct = $existingProduct;
                    }
                } else {
                    // Nouveau produit : créer (SoftDeletes gère automatiquement)
                    $savedProduct = Product::create([
                        'vendor_id'      => $vendorId,
                        'external_id'    => $product['external_id'],
                        'category_id'    => $defaultCategoryId,
                        'name'           => $product['name'],
                        'description'    => strip_tags($product['description'] ?? ''),
                        'price'          => $productPrice,
                        'sku'            => $sku,
                        'stock_quantity' => $product['stock'] ?? 0,
                        'status'         => $product['status'] ?? 'active',
                        'external_data'  => json_encode($product),
                        'last_sync'      => now(),
                        'is_active'      => ($product['status'] ?? 'active') === 'active',
                    ]);
                }

                // Sauvegarder les images du produit
                if (!empty($product['images'])) {
                    Log::info('Images trouvées pour le produit', [
                        'product_id' => $savedProduct->id,
                        'product_name' => $product['name'],
                        'images_count' => count($product['images']),
                        'images' => $product['images']
                    ]);
                    $this->saveProductImages($savedProduct->id, $product['images']);
                } else {
                    Log::warning('Aucune image trouvée pour le produit', [
                        'product_id' => $savedProduct->id,
                        'product_name' => $product['name'],
                        'external_id' => $product['external_id']
                    ]);
                }

                // Sauvegarder les variantes du produit
                if (!empty($product['variants']) && is_array($product['variants'])) {
                    // Validation : si le produit principal n'a pas de prix, vérifier que toutes les variantes en ont
                    if ($productPrice === null) {
                        $this->validateVariantsHavePrices($product['name'], $product['variants']);
                    }

                    $this->saveOrUpdateVariants($savedProduct->id, $product['variants']);
                }

                Log::info('Produit sauvegardé avec logique de prix appliquée', [
                    'product_id' => $savedProduct->id,
                    'external_id' => $product['external_id'],
                    'product_name' => $product['name'],
                    'final_price_logic' => $hasVariants ? 'Prix NULL (géré par variantes)' : 'Prix direct du produit',
                    'variants_count' => $hasVariants ? count($product['variants']) : 0
                ]);
                $syncedCount++;
            } catch (Exception $e) {
                Log::error('Erreur sauvegarde produit', [
                    'vendor_id' => $vendorId,
                    'product_external_id' => $product['external_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Fin sauvegarde produits', ['vendor_id' => $vendorId, 'synced_count' => $syncedCount]);

        return $syncedCount;
    }

    /**
     * Upsert des variantes pour un produit et suppression douce des variantes manquantes
     */
    private function saveOrUpdateVariants(int $productId, array $variants): void
    {
        try {
            $seenExternalIds = [];

            foreach ($variants as $variant) {
                $externalId = $variant['external_id'] ?? null;
                // Générer un external_id de secours si absent
                if (!$externalId) {
                    $hashSource = json_encode([
                        'sku' => $variant['sku'] ?? null,
                        'size' => $variant['size'] ?? null,
                        'color' => $variant['color'] ?? null,
                        'attributes' => $variant['attributes'] ?? []
                    ]);
                    $externalId = substr(hash('sha1', $hashSource), 0, 32);
                }

                $seenExternalIds[] = $externalId;

                $variantPrice = isset($variant['price']) ? (float) $variant['price'] : null;

                $savedVariant = ProductVariant::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'external_id' => $externalId,
                    ],
                    [
                        'sku' => $variant['sku'] ?? null,
                        'price' => $variantPrice,
                        'stock_quantity' => (int) ($variant['stock_quantity'] ?? $variant['stock'] ?? 0),
                        'size' => $variant['size'] ?? null,
                        'color' => $variant['color'] ?? null,
                        'weight' => isset($variant['weight']) ? (float) $variant['weight'] : null,
                        'attributes' => !empty($variant['attributes']) ? $variant['attributes'] : null,
                        'is_active' => (bool) ($variant['is_active'] ?? true),
                    ]
                );

                // Log pour confirmer la sauvegarde du prix de la variante
                Log::info('Variante sauvegardée avec prix', [
                    'product_id' => $productId,
                    'variant_id' => $savedVariant->id,
                    'external_id' => $externalId,
                    'sku' => $variant['sku'] ?? 'N/A',
                    'price' => $variantPrice,
                    'size' => $variant['size'] ?? 'N/A',
                    'color' => $variant['color'] ?? 'N/A'
                ]);
            }

            // Soft-delete des variantes non vues pendant cette synchronisation
            if (!empty($seenExternalIds)) {
                    ProductVariant::where('product_id', $productId)
                    ->whereNotIn('external_id', $seenExternalIds)
                    ->delete();
            }
        } catch (Exception $e) {
            Log::error('Erreur saveOrUpdateVariants', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Valide que toutes les variantes d'un produit ont des prix
     *
     * @param string $productName
     * @param array $variants
     * @throws Exception si une variante n'a pas de prix
     */
    private function validateVariantsHavePrices(string $productName, array $variants): void
    {
        $variantsWithoutPrice = [];

        foreach ($variants as $index => $variant) {
            if (!isset($variant['price']) || $variant['price'] === null || $variant['price'] === '') {
                $variantsWithoutPrice[] = [
                    'index' => $index,
                    'sku' => $variant['sku'] ?? 'N/A',
                    'size' => $variant['size'] ?? 'N/A',
                    'color' => $variant['color'] ?? 'N/A'
                ];
            }
        }

        if (!empty($variantsWithoutPrice)) {
            $errorMessage = "Le produit '{$productName}' a des variantes sans prix : " . json_encode($variantsWithoutPrice);
            Log::error('Validation des prix des variantes échouée', [
                'product_name' => $productName,
                'variants_without_price' => $variantsWithoutPrice
            ]);
            throw new Exception($errorMessage);
        }

        Log::info('Validation des prix des variantes réussie', [
            'product_name' => $productName,
            'variants_count' => count($variants),
            'all_variants_have_prices' => true
        ]);
    }

    /**
     * Sauvegarde les images d'un produit dans la table product_images
     */
    private function saveProductImages(int $productId, array $images): void
    {
        try {
            Log::info('Début sauvegarde images', [
                'product_id' => $productId,
                'total_images' => count($images)
            ]);

            // Supprimer les anciennes images du produit
            $deletedCount = ProductImage::where('product_id', $productId)->delete();
            Log::info('Anciennes images supprimées', [
                'product_id' => $productId,
                'deleted_count' => $deletedCount
            ]);

            $savedCount = 0;
            foreach ($images as $index => $image) {
                if (!empty($image['url'])) {
                    try {
                        $productImage = ProductImage::create([
                            'product_id' => $productId,
                            'path'       => $image['url'],
                            'alt'        => $image['alt'] ?? null,
                            'is_primary' => $image['is_primary'] ?? ($index === 0), // La première image est principale par défaut
                            'position'   => $image['position'] ?? ($index + 1),
                        ]);

                        $savedCount++;
                        Log::info('Image sauvegardée', [
                            'product_id' => $productId,
                            'image_id' => $productImage->id,
                            'url' => $image['url'],
                            'position' => $productImage->position,
                            'is_primary' => $productImage->is_primary
                        ]);
                    } catch (Exception $e) {
                        Log::error('Erreur sauvegarde image individuelle', [
                            'product_id' => $productId,
                            'image_url' => $image['url'],
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::warning('Image ignorée - URL vide', [
                        'product_id' => $productId,
                        'image_index' => $index,
                        'image_data' => $image
                    ]);
                }
            }

            Log::info('Fin sauvegarde images', [
                'product_id' => $productId,
                'total_images' => count($images),
                'saved_count' => $savedCount
            ]);
        } catch (Exception $e) {
            Log::error('Erreur sauvegarde images produit', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Synchronise les images d'un produit spécifique depuis sa plateforme e-commerce
     */
    public function syncProductImages(int $productId): array
    {
        try {
            Log::info('Début synchronisation images produit', ['product_id' => $productId]);

            $product = Product::with('vendor')->find($productId);
            if (!$product || !$product->vendor) {
                Log::error('Produit ou vendeur non trouvé', ['product_id' => $productId]);
                return ['success' => false, 'error' => 'Produit ou vendeur non trouvé'];
            }

            $vendor = $product->vendor;
            Log::info('Configuration vendeur trouvée', [
                'vendor_id' => $vendor->id,
                'ecommerce_platform' => $vendor->ecommerce_platform,
                'has_access_token' => !empty($vendor->access_token),
                'website_url' => $vendor->website_url
            ]);

            if (empty($vendor->ecommerce_platform)) {
                Log::error('Plateforme e-commerce non configurée', ['vendor_id' => $vendor->id]);
                return ['success' => false, 'error' => 'Plateforme e-commerce non configurée'];
            }

            if (empty($vendor->access_token)) {
                Log::error('Token d\'accès manquant', ['vendor_id' => $vendor->id]);
                return ['success' => false, 'error' => 'Token d\'accès manquant'];
            }

            if (empty($vendor->website_url)) {
                Log::error('URL de la boutique manquante', ['vendor_id' => $vendor->id]);
                return ['success' => false, 'error' => 'URL de la boutique manquante'];
            }

            // Récupérer la configuration e-commerce
            $config = [
                'platform' => $vendor->ecommerce_platform,
                'shop_url' => $vendor->website_url,
                'access_token' => $vendor->getDecryptedAccessToken(),
                'api_version' => $vendor->ecommerce_platform_version ?? '2024-01',
            ];

            Log::info('Configuration e-commerce préparée', [
                'platform' => $config['platform'],
                'shop_url' => $config['shop_url'],
                'has_access_token' => !empty($config['access_token']),
                'api_version' => $config['api_version']
            ]);

            // Récupérer le produit depuis la plateforme
            $platformProduct = $this->getProductFromPlatform($product->external_id, $config);
            if (empty($platformProduct)) {
                Log::error('Produit non trouvé sur la plateforme', [
                    'product_id' => $productId,
                    'external_id' => $product->external_id,
                    'platform' => $config['platform']
                ]);
                return ['success' => false, 'error' => 'Produit non trouvé sur la plateforme'];
            }

            Log::info('Produit récupéré depuis la plateforme', [
                'product_id' => $productId,
                'has_images' => !empty($platformProduct['images']),
                'images_count' => count($platformProduct['images'] ?? [])
            ]);

            // Sauvegarder les images
            if (!empty($platformProduct['images'])) {
                $this->saveProductImages($productId, $platformProduct['images']);

                return [
                    'success' => true,
                    'message' => 'Images synchronisées avec succès',
                    'images_count' => count($platformProduct['images'])
                ];
            }

            Log::warning('Aucune image trouvée pour le produit', [
                'product_id' => $productId,
                'external_id' => $product->external_id
            ]);
            return ['success' => false, 'error' => 'Aucune image trouvée pour ce produit'];

        } catch (Exception $e) {
            Log::error('Erreur synchronisation images produit', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => 'Erreur lors de la synchronisation: ' . $e->getMessage()];
        }
    }

    /**
     * Récupère un produit spécifique depuis sa plateforme e-commerce
     */
    private function getProductFromPlatform(string $externalId, array $config): ?array
    {
        return match ($config['platform'] ?? '') {
            'shopify'     => $this->getShopifyProduct($externalId, $config),
            'woocommerce' => $this->getWooCommerceProduct($externalId, $config),
            'prestashop'  => $this->getPrestaShopProduct($externalId, $config),
            'magento'     => $this->getMagentoProduct($externalId, $config),
            default       => null,
        };
    }

    private function configureWebhooks(array $config): bool
    {
        try {
            return match ($config['platform'] ?? '') {
                'shopify'     => $this->configureShopifyWebhooks($config),
                'prestashop'  => $this->configurePrestaShopWebhooks($config),
                'woocommerce' => $this->configureWooCommerceWebhooks($config),
                'magento'     => $this->configureMagentoWebhooks($config),
                default       => (Log::info('Webhooks non configurés pour cette plateforme') || false),
            };
        } catch (Exception $e) {
            Log::error('Erreur configuration webhooks', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function configureShopifyWebhooks(array $config): bool
    {
        try {
            $shopUrl = $config['shop_url'] ?? '';
            $accessToken = $config['access_token'] ?? '';
            $apiVersion = $config['api_version'] ?? '2024-01';

            if (empty($shopUrl) || empty($accessToken)) return false;
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $baseUrl = env('APP_URL', 'http://localhost:8000');
            $webhookUrl = $baseUrl.'/api/webhooks/shopify';

            $webhooks = ['products/create','products/update','products/delete','inventory_levels/update'];

            foreach ($webhooks as $topic) {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])->post($shopUrl."/admin/api/{$apiVersion}/webhooks.json", [
                    'webhook' => [
                        'topic' => $topic,
                        'address' => $webhookUrl,
                        'format' => 'json',
                    ],
                ]);

                if ($response->successful()) {
                    Log::info("Webhook Shopify configuré: {$topic}");
                } else {
                    Log::warning("Erreur configuration webhook Shopify {$topic}: ".$response->status());
                }
            }

            return true;
        } catch (Exception $e) {
            Log::error('Erreur configuration webhooks Shopify', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function configurePrestaShopWebhooks(array $config): bool
    {
        try {
            Log::info('Instructions webhooks PrestaShop générées');
            return true;
        } catch (Exception $e) {
            Log::error('Erreur configuration webhooks PrestaShop', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function configureWooCommerceWebhooks(array $config): bool
    {
        try {
            $shopUrl = $config['shop_url'] ?? '';
            $accessToken = $config['access_token'] ?? '';
            if (empty($shopUrl) || empty($accessToken)) return false;
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $baseUrl = env('APP_URL', 'http://localhost:8000');
            $webhookUrl = $baseUrl.'/api/webhooks/woocommerce';

            $webhooks = ['product.created','product.updated','product.deleted','product.restored'];

            foreach ($webhooks as $topic) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post($shopUrl.'/wp-json/wc/v3/webhooks', [
                    'name' => ucfirst(str_replace('.', ' ', $topic)),
                    'topic' => $topic,
                    'delivery_url' => $webhookUrl,
                    'status' => 'active',
                ]);

                if ($response->successful()) {
                    Log::info("Webhook WooCommerce configuré: {$topic}");
                } else {
                    Log::warning("Erreur configuration webhook WooCommerce {$topic}: ".$response->status());
                }
            }

            return true;
        } catch (Exception $e) {
            Log::error('Erreur configuration webhooks WooCommerce', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function configureMagentoWebhooks(array $config): bool
    {
        try {
            Log::info('Instructions webhooks Magento générées');
            return true;
        } catch (Exception $e) {
            Log::error('Erreur configuration webhooks Magento', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getShopifyProducts(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        $apiVersion = $config['api_version'] ?? '2024-01';

        if (empty($shopUrl) || empty($accessToken)) return [];

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            Log::info('Récupération produits Shopify', [
                'shop_url' => $shopUrl,
                'api_version' => $apiVersion,
                'has_access_token' => !empty($accessToken)
            ]);

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/admin/api/{$apiVersion}/products.json");

            Log::info('Réponse API Shopify', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_size' => strlen($response->body())
            ]);

            if ($response->successful()) {
                $products = $response->json('products', []);
                Log::info('Produits récupérés depuis Shopify', [
                    'count' => count($products),
                    'first_product_keys' => $products[0] ? array_keys($products[0]) : []
                ]);

                $normalized = [];

                foreach ($products as $index => $product) {
                    Log::info('Traitement produit Shopify', [
                        'index' => $index,
                        'product_id' => $product['id'] ?? 'N/A',
                        'has_images' => !empty($product['images']),
                        'images_count' => count($product['images'] ?? []),
                        'images_structure' => $product['images'] ? array_keys($product['images'][0] ?? []) : []
                    ]);

                    // Récupérer toutes les images du produit
                    $images = [];
                    if (!empty($product['images'])) {
                        foreach ($product['images'] as $imgIndex => $image) {
                            $images[] = [
                                'url' => $image['src'] ?? null,
                                'alt' => $image['alt'] ?? null,
                                'position' => $imgIndex + 1,
                                'is_primary' => $imgIndex === 0, // La première image est principale
                                'width' => $image['width'] ?? null,
                                'height' => $image['height'] ?? null,
                            ];
                        }

                        Log::info('Images normalisées pour le produit', [
                            'product_id' => $product['id'],
                            'images_count' => count($images),
                            'first_image' => $images[0] ?? 'N/A'
                        ]);
                    } else {
                        Log::warning('Aucune image trouvée pour le produit', [
                            'product_id' => $product['id'],
                            'product_keys' => array_keys($product)
                        ]);
                    }

                    $normalized[] = [
                        'external_id' => $product['id'],
                        'name'        => $product['title'],
                        'description' => strip_tags($product['body_html'] ?? ''),
                        'price'       => $this->extractPrice($product),
                        'sku'         => $this->extractSku($product),
                        'stock'       => $this->extractStock($product),
                        'status'      => ($product['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
                        'images'      => $images, // Toutes les images avec métadonnées
                        'image_url'   => $product['image']['src'] ?? null, // Garder pour compatibilité
                        'product_url' => $product['handle'] ?? null,
                        'variants'    => $this->normalizeShopifyVariants($product),
                    ];
                }

                Log::info('Produits normalisés avec images', [
                    'total_normalized' => count($normalized),
                    'products_with_images' => count(array_filter($normalized, fn($p) => !empty($p['images'])))
                ]);

                return $normalized;
            }

            Log::error('Réponse API Shopify non réussie', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return [];
        } catch (Exception $e) {
            Log::error('Erreur récupération produits Shopify', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Normalise les variantes Shopify
     */
    private function normalizeShopifyVariants(array $product): array
    {
        $variants = [];
        $optionNames = [];
        if (!empty($product['options'])) {
            foreach ($product['options'] as $opt) {
                $pos = $opt['position'] ?? null;
                if ($pos) { $optionNames[$pos] = $opt['name'] ?? ('option'.$pos); }
            }
        }

        foreach (($product['variants'] ?? []) as $v) {
            $attributes = [];
            foreach ([1,2,3] as $pos) {
                $key = 'option'.$pos;
                if (!empty($v[$key])) {
                    $label = $optionNames[$pos] ?? $key;
                    $attributes[$label] = $v[$key];
                }
            }
            $size = null; $color = null;
            foreach ($attributes as $label => $value) {
                $l = mb_strtolower($label);
                if (preg_match('/size|taille/', $l)) { $size = $value; }
                if (preg_match('/color|couleur/', $l)) { $color = $value; }
            }

            $variants[] = [
                'external_id'    => $v['id'] ?? null,
                'sku'            => $v['sku'] ?? null,
                'price'          => isset($v['price']) ? (float) $v['price'] : null,
                'stock_quantity' => (int) ($v['inventory_quantity'] ?? 0),
                'size'           => $size,
                'color'          => $color,
                'attributes'     => $attributes,
                'is_active'      => (bool) ($v['available'] ?? true),
            ];
        }

        return $variants;
    }

    /**
     * Récupère un produit spécifique depuis Shopify
     */
    private function getShopifyProduct(string $externalId, array $config): ?array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        $apiVersion = $config['api_version'] ?? '2024-01';

        if (empty($shopUrl) || empty($accessToken)) return null;

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/admin/api/{$apiVersion}/products/{$externalId}.json");

            if ($response->successful()) {
                $product = $response->json('product');
                if (!$product) return null;

                // Récupérer toutes les images du produit
                $images = [];
                if (!empty($product['images'])) {
                    foreach ($product['images'] as $index => $image) {
                        $images[] = [
                            'url' => $image['src'] ?? null,
                            'alt' => $image['alt'] ?? null,
                            'position' => $index + 1,
                            'is_primary' => $index === 0, // La première image est principale
                            'width' => $image['width'] ?? null,
                            'height' => $image['height'] ?? null,
                        ];
                    }
                }

                return [
                    'external_id' => $product['id'],
                    'name'        => $product['title'],
                    'description' => strip_tags($product['body_html'] ?? ''),
                    'price'       => $this->extractPrice($product),
                    'sku'         => $this->extractSku($product),
                    'stock'       => $this->extractStock($product),
                    'status'      => ($product['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
                    'images'      => $images,
                    'image_url'   => $product['image']['src'] ?? null,
                    'product_url' => $product['handle'] ?? null,
                    'variants'    => $this->normalizeShopifyVariants($product),
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Erreur récupération produit Shopify', ['external_id' => $externalId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getWooCommerceProducts(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return [];

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/wp-json/wc/v3/products');

            if ($response->successful()) {
                $products = $response->json();
                $normalized = [];

                foreach ($products as $product) {
                    // Récupérer toutes les images du produit
                    $images = [];
                    if (!empty($product['images'])) {
                        foreach ($product['images'] as $index => $image) {
                            $images[] = [
                                'url' => $image['src'] ?? null,
                                'alt' => $image['alt'] ?? null,
                                'position' => $index + 1,
                                'is_primary' => $index === 0, // La première image est principale
                                'width' => $image['width'] ?? null,
                                'height' => $image['height'] ?? null,
                            ];
                        }
                    }

                    $normalized[] = [
                        'external_id' => $product['id'],
                        'name'        => $product['name'],
                        'description' => strip_tags($product['description'] ?? ''),
                        'price'       => $product['price'] ?? 0,
                        'sku'         => $product['sku'] ?? '',
                        'stock'       => $product['stock_quantity'] ?? 0,
                        'status'      => ($product['status'] ?? 'publish') === 'publish' ? 'active' : 'inactive',
                        'images'      => $images, // Toutes les images avec métadonnées
                        'image_url'   => $product['images'][0]['src'] ?? null, // Garder pour compatibilité
                        'product_url' => $product['permalink'] ?? null,
                        'variants'    => $this->normalizeWooCommerceVariants($config, $product),
                    ];
                }

                return $normalized;
            }

            return [];
        } catch (Exception $e) {
            Log::error('Erreur récupération produits WooCommerce', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Normalise les variantes WooCommerce (nécessite un call additionnel pour produits variables)
     */
    private function normalizeWooCommerceVariants(array $config, array $product): array
    {
        $variants = [];
        $type = $product['type'] ?? '';
        if ($type !== 'variable') {
            return $variants;
        }

        try {
            $shopUrl = $config['shop_url'] ?? '';
            $accessToken = $config['access_token'] ?? '';
            if (empty($shopUrl) || empty($accessToken)) return [];
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/wp-json/wc/v3/products/'.($product['id'] ?? '').'/variations');

            if (!$resp->successful()) return [];
            $items = $resp->json();

            foreach ($items as $v) {
                // Map attributes list [{name, option}]
                $attributes = [];
                $size = null; $color = null;
                foreach (($v['attributes'] ?? []) as $attr) {
                    $name = $attr['name'] ?? '';
                    $value = $attr['option'] ?? '';
                    if ($name !== '' && $value !== '') {
                        $attributes[$name] = $value;
                        $lower = mb_strtolower($name);
                        if (preg_match('/size|taille/', $lower)) { $size = $value; }
                        if (preg_match('/color|couleur/', $lower)) { $color = $value; }
                    }
                }

                $variants[] = [
                    'external_id'    => $v['id'] ?? null,
                    'sku'            => $v['sku'] ?? null,
                    'price'          => isset($v['price']) ? (float) $v['price'] : null,
                    'stock_quantity' => (int) ($v['stock_quantity'] ?? 0),
                    'size'           => $size,
                    'color'          => $color,
                    'attributes'     => $attributes,
                    'is_active'      => ($v['status'] ?? 'publish') === 'publish',
                ];
            }
        } catch (Exception $e) {
            Log::warning('normalizeWooCommerceVariants error', ['error' => $e->getMessage()]);
        }

        return $variants;
    }

    /**
     * Récupère un produit spécifique depuis WooCommerce
     */
    private function getWooCommerceProduct(string $externalId, array $config): ?array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return null;

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/wp-json/wc/v3/products/{$externalId}");

            if ($response->successful()) {
                $product = $response->json();
                if (!$product) return null;

                // Récupérer toutes les images du produit
                $images = [];
                if (!empty($product['images'])) {
                    foreach ($product['images'] as $index => $image) {
                        $images[] = [
                            'url' => $image['src'] ?? null,
                            'alt' => $image['alt'] ?? null,
                            'position' => $index + 1,
                            'is_primary' => $index === 0, // La première image est principale
                            'width' => $image['width'] ?? null,
                            'height' => $image['height'] ?? null,
                        ];
                    }
                }

                return [
                    'external_id' => $product['id'],
                    'name'        => $product['name'],
                    'description' => $product['description'] ?? '',
                    'price'       => $product['price'] ?? 0,
                    'sku'         => $product['sku'] ?? '',
                    'stock'       => $product['stock_quantity'] ?? 0,
                    'status'      => ($product['status'] ?? 'publish') === 'publish' ? 'active' : 'inactive',
                    'images'      => $images,
                    'image_url'   => $product['images'][0]['src'] ?? null,
                    'product_url' => $product['permalink'] ?? null,
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Erreur récupération produit WooCommerce', ['external_id' => $externalId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getPrestaShopProducts(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return [];

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/api/products');

            if ($response->successful()) {
                $data = $response->json();
                $products = $data['products'] ?? [];
                $normalized = [];

                foreach ($products as $product) {
                    // Récupérer toutes les images du produit
                    $images = [];
                    if (!empty($product['associations']['images'])) {
                        foreach ($product['associations']['images'] as $index => $imageId) {
                            $images[] = [
                                'url' => $shopUrl.'/images/products/'.$imageId.'.jpg',
                                'alt' => $product['name'] ?? null,
                                'position' => $index + 1,
                                'is_primary' => $index === 0, // La première image est principale
                            ];
                        }
                    } elseif (!empty($product['image_url'])) {
                        // Fallback sur l'image principale si pas d'associations
                        $images[] = [
                            'url' => $product['image_url'],
                            'alt' => $product['name'] ?? null,
                            'position' => 1,
                            'is_primary' => true,
                        ];
                    }

                    $normalized[] = [
                        'external_id' => $product['id'],
                        'name'        => $product['name'] ?? '',
                        'description' => strip_tags($product['description'] ?? ''),
                        'price'       => $product['price'] ?? 0,
                        'sku'         => $product['reference'] ?? '',
                        'stock'       => $product['quantity'] ?? 0,
                        'status'      => !empty($product['active']) ? 'active' : 'inactive',
                        'images'      => $images, // Toutes les images avec métadonnées
                        'image_url'   => $product['image_url'] ?? null, // Garder pour compatibilité
                        'product_url' => $product['link_rewrite'] ?? null,
                        'variants'    => $this->normalizePrestaShopVariants($config, $product),
                    ];
                }

                return $normalized;
            }

            return [];
        } catch (Exception $e) {
            Log::error('Erreur récupération produits PrestaShop', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Normalise les variantes PrestaShop (combinations)
     */
    private function normalizePrestaShopVariants(array $config, array $product): array
    {
        $variants = [];
        try {
            $shopUrl = $config['shop_url'] ?? '';
            $accessToken = $config['access_token'] ?? '';
            if (empty($shopUrl) || empty($accessToken)) return [];
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $productId = $product['id'] ?? null;
            if (!$productId) return [];

            // Simplification: récupérer toutes les combinations du produit
            $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/api/combinations', [
                'display' => 'full',
                'filter[id_product]' => '['.$productId.']',
            ]);

            if (!$resp->successful()) return [];
            $data = $resp->json();
            $combinations = $data['combinations'] ?? [];

            foreach ($combinations as $comb) {
                $attributes = [];
                $size = null; $color = null;

                // Presta: associations.product_option_values → il peut falloir une requête supplémentaire pour les labels
                if (!empty($comb['associations']['product_option_values'])) {
                    foreach ($comb['associations']['product_option_values'] as $pov) {
                        $valueId = $pov['id'] ?? null;
                        if ($valueId) {
                            // Tentative de label direct si présent (sinon fallback générique)
                            $label = 'Option';
                            $val = (string) $valueId;
                            $attributes[$label] = $val;
                        }
                    }
                }

                // Dérive size/color si clés connues dans attributes (placeholder)
                foreach ($attributes as $label => $value) {
                    $l = mb_strtolower($label);
                    if (preg_match('/size|taille/', $l)) { $size = $value; }
                    if (preg_match('/color|couleur/', $l)) { $color = $value; }
                }

                $variants[] = [
                    'external_id'    => $comb['id'] ?? null,
                    'sku'            => $comb['reference'] ?? null,
                    'price'          => isset($comb['price']) ? (float) $comb['price'] : null,
                    'stock_quantity' => (int) ($comb['quantity'] ?? 0),
                    'size'           => $size,
                    'color'          => $color,
                    'attributes'     => $attributes,
                    'is_active'      => true,
                ];
            }
        } catch (Exception $e) {
            Log::warning('normalizePrestaShopVariants error', ['error' => $e->getMessage()]);
        }

        return $variants;
    }

    /**
     * Récupère un produit spécifique depuis PrestaShop
     */
    private function getPrestaShopProduct(string $externalId, array $config): ?array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return null;

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/api/products/{$externalId}");

            if ($response->successful()) {
                $data = $response->json();
                $product = $data['product'] ?? null;
                if (!$product) return null;

                // Récupérer toutes les images du produit
                $images = [];
                if (!empty($product['associations']['images'])) {
                    foreach ($product['associations']['images'] as $index => $imageId) {
                        $images[] = [
                            'url' => $shopUrl.'/images/products/'.$imageId.'.jpg',
                            'alt' => $product['name'] ?? null,
                            'position' => $index + 1,
                            'is_primary' => $index === 0, // La première image est principale
                        ];
                    }
                } elseif (!empty($product['image_url'])) {
                    // Fallback sur l'image principale si pas d'associations
                    $images[] = [
                        'url' => $product['image_url'],
                        'alt' => $product['name'] ?? null,
                        'position' => 1,
                        'is_primary' => true,
                    ];
                }

                return [
                    'external_id' => $product['id'],
                    'name'        => $product['name'] ?? '',
                    'description' => $product['description'] ?? '',
                    'price'       => $product['price'] ?? 0,
                    'sku'         => $product['reference'] ?? '',
                    'stock'       => $product['quantity'] ?? 0,
                    'status'      => !empty($product['active']) ? 'active' : 'inactive',
                    'images'      => $images,
                    'image_url'   => $product['image_url'] ?? null,
                    'product_url' => $product['link_rewrite'] ?? null,
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Erreur récupération produit PrestaShop', ['external_id' => $externalId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function getMagentoProducts(array $config): array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return [];

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/rest/V1/products');

            if ($response->successful()) {
                $products = $response->json();
                $normalized = [];

                foreach ($products as $product) {
                    // Récupérer toutes les images du produit
                    $images = [];
                    if (!empty($product['media_gallery_entries'])) {
                        foreach ($product['media_gallery_entries'] as $index => $media) {
                            $images[] = [
                                'url' => $shopUrl.'/media/catalog/product'.$media['file'],
                                'alt' => $media['label'] ?? $product['name'] ?? null,
                                'position' => $media['position'] ?? ($index + 1),
                                'is_primary' => $index === 0, // La première image est principale
                                'width' => $media['width'] ?? null,
                                'height' => $media['height'] ?? null,
                            ];
                        }
                    }

                    $normalized[] = [
                        'external_id' => $product['id'] ?? ($product['sku'] ?? null),
                        'name'        => $product['name'] ?? '',
                        'description' => strip_tags($product['description'] ?? ''),
                        'price'       => $product['price'] ?? 0,
                        'sku'         => $product['sku'] ?? '',
                        'stock'       => $product['stock'] ?? 0,
                        'status'      => $product['status'] ?? 'inactive',
                        'images'      => $images, // Toutes les images avec métadonnées
                        'image_url'   => $product['media_gallery_entries'][0]['file'] ?? null, // Garder pour compatibilité
                        'product_url' => $product['url_key'] ?? null,
                        'variants'    => $this->normalizeMagentoVariants($config, $product),
                    ];
                }

                return $normalized;
            }

            return [];
        } catch (Exception $e) {
            Log::error('Erreur récupération produits Magento', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Normalise les variantes Magento (children des produits configurables)
     */
    private function normalizeMagentoVariants(array $config, array $product): array
    {
        $variants = [];
        try {
            $shopUrl = $config['shop_url'] ?? '';
            $accessToken = $config['access_token'] ?? '';
            if (empty($shopUrl) || empty($accessToken)) return [];
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $sku = $product['sku'] ?? null;
            if (!$sku) return [];

            $resp = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl.'/rest/V1/configurable-products/'.$sku.'/children');

            if (!$resp->successful()) return [];
            $children = $resp->json();

            foreach ($children as $child) {
                $attributes = [];
                $size = null; $color = null;
                foreach (($child['custom_attributes'] ?? []) as $attr) {
                    $code = $attr['attribute_code'] ?? '';
                    $val  = $attr['value'] ?? '';
                    if ($code !== '') {
                        $attributes[$code] = $val;
                        $l = mb_strtolower($code);
                        if (preg_match('/size|taille/', $l)) { $size = $val; }
                        if (preg_match('/color|couleur/', $l)) { $color = $val; }
                    }
                }

                $variants[] = [
                    'external_id'    => $child['id'] ?? ($child['sku'] ?? null),
                    'sku'            => $child['sku'] ?? null,
                    'price'          => isset($child['price']) ? (float) $child['price'] : null,
                    'stock_quantity' => (int) ($child['stock'] ?? 0),
                    'size'           => $size,
                    'color'          => $color,
                    'attributes'     => $attributes,
                    'is_active'      => ($child['status'] ?? 1) == 1,
                ];
            }
        } catch (Exception $e) {
            Log::warning('normalizeMagentoVariants error', ['error' => $e->getMessage()]);
        }

        return $variants;
    }

    /**
     * Récupère un produit spécifique depuis Magento
     */
    private function getMagentoProduct(string $externalId, array $config): ?array
    {
        $shopUrl = $config['shop_url'] ?? '';
        $accessToken = $config['access_token'] ?? '';
        if (empty($shopUrl) || empty($accessToken)) return null;

        try {
            if (!str_starts_with($shopUrl, 'https://')) $shopUrl = 'https://'.$shopUrl;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->get($shopUrl."/rest/V1/products/{$externalId}");

            if ($response->successful()) {
                $product = $response->json();
                if (!$product) return null;

                // Récupérer toutes les images du produit
                $images = [];
                if (!empty($product['media_gallery_entries'])) {
                    foreach ($product['media_gallery_entries'] as $index => $media) {
                        $images[] = [
                            'url' => $shopUrl.'/media/catalog/product'.$media['file'],
                            'alt' => $media['label'] ?? $product['name'] ?? null,
                            'position' => $media['position'] ?? ($index + 1),
                            'is_primary' => $index === 0, // La première image est principale
                            'width' => $media['width'] ?? null,
                            'height' => $media['height'] ?? null,
                        ];
                    }
                }

                return [
                    'external_id' => $product['id'] ?? ($product['sku'] ?? null),
                    'name'        => $product['name'] ?? '',
                    'description' => $product['description'] ?? '',
                    'price'       => $product['price'] ?? 0,
                    'sku'         => $product['sku'] ?? '',
                    'stock'       => $product['stock'] ?? 0,
                    'status'      => $product['status'] ?? 'inactive',
                    'images'      => $images,
                    'image_url'   => $product['media_gallery_entries'][0]['file'] ?? null,
                    'product_url' => $product['url_key'] ?? null,
                ];
            }

            return null;
        } catch (Exception $e) {
            Log::error('Erreur récupération produit Magento', ['external_id' => $externalId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Helpers d’extraction Shopify */
    private function extractPrice(array $product): float
    {
        if (!empty($product['variants'])) {
            return (float) ($product['variants'][0]['price'] ?? 0);
        }
        return 0;
    }

    private function extractSku(array $product): string
    {
        if (!empty($product['variants'])) {
            return $product['variants'][0]['sku'] ?? '';
        }
        return '';
    }

    private function extractStock(array $product): int
    {
        if (!empty($product['variants'])) {
            return (int) ($product['variants'][0]['inventory_quantity'] ?? 0);
        }
        return 0;
    }

    /** Validation config plateformes */
    private function validateConfig(array $config): array
    {
        $platform = $config['platform'] ?? '';
        $shopUrl  = $config['shop_url'] ?? '';

        if (empty($platform)) return ['valid' => false, 'error' => 'La plateforme est requise'];
        if (empty($shopUrl))  return ['valid' => false, 'error' => "L'URL de la boutique est requise"];
        if (empty($config['access_token'])) return ['valid' => false, 'error' => "Le token d'accès est requis"];

        return ['valid' => true];
    }

    /** Préférences de notifications */
    public function getNotificationPreferences(int $userId): array
    {
        try {
            $vendor = Vendor::where('user_id', $userId)->first();
            if (!$vendor) return ['success' => false, 'message' => 'Vendeur non trouvé'];

            return [
                'success' => true,
                'data' => [
                    'is_notified'                 => (bool) $vendor->is_notified,
                    'is_notified_new_order'       => (bool) $vendor->is_notified_new_order,
                    'is_notified_order_canceled'  => (bool) $vendor->is_notified_order_canceled,
                    'is_notified_low_stock'       => (bool) $vendor->is_notified_low_stock,
                    'is_notified_app_maintenance' => (bool) $vendor->is_notified_app_maintenance,
                    'is_notified_app_update'      => (bool) $vendor->is_notified_app_update,
                ],
            ];
        } catch (Exception $e) {
            Log::error('Erreur récupération préférences notifications', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur lors de la récupération des préférences', 'error' => $e->getMessage()];
        }
    }

    public function updateNotificationPreferences(int $userId, array $preferences): array
    {
        try {
            $vendor = Vendor::where('user_id', $userId)->first();
            if (!$vendor) return ['success' => false, 'message' => 'Vendeur non trouvé'];

            if (isset($preferences['is_notified']) && !$preferences['is_notified']) {
                $vendor->update([
                    'is_notified'                 => false,
                    'is_notified_new_order'       => false,
                    'is_notified_order_canceled'  => false,
                    'is_notified_low_stock'       => false,
                    'is_notified_app_maintenance' => false,
                    'is_notified_app_update'      => false,
                ]);
            } else {
                $vendor->update($preferences);
            }

            $vendor->refresh();

            return [
                'success' => true,
                'message' => 'Préférences mises à jour avec succès',
                'data' => [
                    'is_notified'                 => (bool) $vendor->is_notified,
                    'is_notified_new_order'       => (bool) $vendor->is_notified_new_order,
                    'is_notified_order_canceled'  => (bool) $vendor->is_notified_order_canceled,
                    'is_notified_low_stock'       => (bool) $vendor->is_notified_low_stock,
                    'is_notified_app_maintenance' => (bool) $vendor->is_notified_app_maintenance,
                    'is_notified_app_update'      => (bool) $vendor->is_notified_app_update,
                ],
            ];
        } catch (Exception $e) {
            Log::error('Erreur mise à jour préférences notifications', ['user_id' => $userId, 'preferences' => $preferences, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur lors de la mise à jour des préférences', 'error' => $e->getMessage()];
        }
    }

    /**
     * Synchronisation intelligente qui préserve les modifications locales
     * et met à jour uniquement les champs qui ont changé côté boutique
     */
    public function smartSyncProducts(int $vendorId, array $config): array
    {
        try {
            Log::info('Début synchronisation intelligente', ['vendor_id' => $vendorId]);

            // Récupérer les produits de la plateforme
            $platformProducts = $this->getProductsFromPlatform($config);
            if (empty($platformProducts)) {
                return [
                    'success' => true,
                    'products_count' => 0,
                    'updated_products' => 0,
                    'new_products' => 0,
                    'preserved_products' => 0,
                    'message' => 'Aucun produit trouvé sur la plateforme'
                ];
            }

            $updatedCount = 0;
            $newCount = 0;
            $preservedCount = 0;
            $defaultCategoryId = null;

            foreach ($platformProducts as $platformProduct) {
                try {
                    $externalId = $platformProduct['external_id'] ?? null;
                    if (!$externalId) {
                        Log::warning('Produit sans external_id, ignoré', ['product' => $platformProduct]);
                        continue;
                    }

                    // Chercher le produit existant (inclure les supprimés pour la restauration)
                    $existingProduct = Product::withTrashed()
                        ->where('vendor_id', $vendorId)
                        ->where('external_id', $externalId)
                        ->first();

                    if ($existingProduct) {
                        // Si le produit existe et n'est pas supprimé (soft delete), on ne l'ajoute pas
                        if (!$existingProduct->trashed()) {
                            Log::info('Produit existant non supprimé, ignoré', [
                                'product_id' => $existingProduct->id,
                                'external_id' => $externalId,
                                'name' => $platformProduct['name'] ?? 'N/A',
                            ]);
                            $preservedCount++;
                            continue; // Passer au produit suivant
                        }
                        
                        // Si le produit est supprimé (soft delete), on le restaure et on le met à jour
                        if ($existingProduct->trashed()) {
                            Log::info('Produit supprimé trouvé, restauration et mise à jour', [
                                'product_id' => $existingProduct->id,
                                'external_id' => $externalId,
                                'name' => $platformProduct['name'] ?? 'N/A',
                            ]);
                            
                            // Restaurer le produit avec SoftDeletes
                            $existingProduct->restore();
                            
                            // Produit existant restauré : comparer et mettre à jour intelligemment
                            $updateResult = $this->smartUpdateProduct($existingProduct, $platformProduct, $defaultCategoryId);
                            if ($updateResult['updated']) {
                                $updatedCount++;
                            } else {
                                $preservedCount++;
                            }
                        }
                    } else {
                        // Nouveau produit : créer (SoftDeletes gère automatiquement)
                        $this->createNewProduct($vendorId, $platformProduct, $defaultCategoryId);
                        $newCount++;
                    }

                } catch (Exception $e) {
                    Log::error('Erreur traitement produit synchronisation intelligente', [
                        'external_id' => $platformProduct['external_id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Mettre à jour le timestamp de dernière synchronisation
            $vendor = Vendor::find($vendorId);
            if ($vendor) {
                $vendor->update(['last_synced_at' => now()]);
            }

            Log::info('Synchronisation intelligente terminée', [
                'vendor_id' => $vendorId,
                'total_platform_products' => count($platformProducts),
                'updated_products' => $updatedCount,
                'new_products' => $newCount,
                'preserved_products' => $preservedCount
            ]);

            return [
                'success' => true,
                'products_count' => count($platformProducts),
                'updated_products' => $updatedCount,
                'new_products' => $newCount,
                'preserved_products' => $preservedCount,
                'message' => "Synchronisation terminée : {$updatedCount} produits mis à jour, {$newCount} nouveaux, {$preservedCount} préservés"
            ];

        } catch (Exception $e) {
            Log::error('Erreur synchronisation intelligente', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la synchronisation intelligente',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Met à jour intelligemment un produit existant en préservant les modifications locales
     */
    private function smartUpdateProduct(Product $existingProduct, array $platformProduct, ?int $defaultCategoryId): array
    {
        $changes = [];
        $hasChanges = false;

        // Champs à comparer et potentiellement mettre à jour
        $fieldsToCheck = [
            'name' => 'name',
            'description' => 'description',
            'stock_quantity' => 'stock',
            'status' => 'status'
        ];

        // Gestion intelligente du prix selon la présence de variantes
        $hasVariants = !empty($platformProduct['variants']) && is_array($platformProduct['variants']) && count($platformProduct['variants']) > 0;
        $newPrice = $hasVariants ? null : ($platformProduct['price'] ?? 0);

        // Comparer le prix avec la logique intelligente
        if ($newPrice !== $existingProduct->price) {
            $changes['price'] = [
                'old' => $existingProduct->price,
                'new' => $newPrice,
                'reason' => $hasVariants ? 'Prix NULL (produit avec variantes)' : 'Prix direct du produit'
            ];
            $hasChanges = true;
        }

        foreach ($fieldsToCheck as $dbField => $platformField) {
            $platformValue = $platformProduct[$platformField] ?? null;
            $currentValue = $existingProduct->$dbField;

            // Convertir les types pour la comparaison
            if (in_array($dbField, ['stock_quantity', 'stock'])) {
                $platformValue = (int) $platformValue;
                $currentValue = (int) $currentValue;
            }

            // Vérifier si la valeur a changé côté plateforme
            if ($platformValue !== null && $platformValue !== $currentValue) {
                $changes[$dbField] = [
                    'old' => $currentValue,
                    'new' => $platformValue
                ];
                $hasChanges = true;
            }
        }

        // Mettre à jour uniquement si des changements sont détectés
        if ($hasChanges) {
            $updateData = [];
            foreach ($changes as $field => $change) {
                $updateData[$field] = $change['new'];
            }

            // Ajouter les champs de synchronisation
            $updateData['external_data'] = json_encode($platformProduct);
            $updateData['last_sync'] = now();
            $updateData['is_active'] = ($platformProduct['status'] ?? 'active') === 'active';

            $existingProduct->update($updateData);

            Log::info('Produit mis à jour intelligemment', [
                'product_id' => $existingProduct->id,
                'external_id' => $existingProduct->external_id,
                'changes' => $changes
            ]);

            // Mettre à jour les images si elles ont changé
            if (!empty($platformProduct['images'])) {
                $this->updateProductImages($existingProduct->id, $platformProduct['images']);
            }

            // Mettre à jour les variantes si elles ont changé
            if (!empty($platformProduct['variants']) && is_array($platformProduct['variants'])) {
                // Validation : si le produit principal n'a pas de prix, vérifier que toutes les variantes en ont
                if ($newPrice === null) {
                    $this->validateVariantsHavePrices($platformProduct['name'], $platformProduct['variants']);
                }

                $this->updateProductVariants($existingProduct->id, $platformProduct['variants']);
            }

            return ['updated' => true, 'changes' => $changes];
        }

        // Aucun changement détecté
        Log::info('Produit préservé (aucun changement détecté)', [
            'product_id' => $existingProduct->id,
            'external_id' => $existingProduct->external_id
        ]);

        return ['updated' => false, 'changes' => []];
    }

    /**
     * Crée un nouveau produit
     */
    private function createNewProduct(int $vendorId, array $platformProduct, ?int $defaultCategoryId): void
    {
        $sku = $platformProduct['sku'] ?? ('SYNC_'.$platformProduct['external_id'].'_'.time());

        // Logique intelligente pour le prix :
        // Si le produit a des variantes, le prix principal = NULL (les prix sont dans les variantes)
        // Sinon, on garde le prix du produit
        $hasVariants = !empty($platformProduct['variants']) && is_array($platformProduct['variants']) && count($platformProduct['variants']) > 0;
        $productPrice = $hasVariants ? null : ($platformProduct['price'] ?? 0);

        Log::info('Création nouveau produit avec logique de prix intelligente', [
            'product_name' => $platformProduct['name'],
            'external_id' => $platformProduct['external_id'],
            'has_variants' => $hasVariants,
            'variants_count' => $hasVariants ? count($platformProduct['variants']) : 0,
            'original_price' => $platformProduct['price'] ?? 'N/A',
            'final_price' => $productPrice,
            'price_logic' => $hasVariants ? 'NULL (prix dans variantes)' : 'Prix du produit'
        ]);

        $product = \App\Models\Product::create([
            'vendor_id'      => $vendorId,
            'category_id'    => $defaultCategoryId,
            'external_id'    => $platformProduct['external_id'],
            'name' => $platformProduct['name'],
            'description'    => strip_tags($platformProduct['description'] ?? ''),
            'price'          => $productPrice,
            'sku'            => $sku,
            'stock_quantity' => $platformProduct['stock'] ?? 0,
            'status'         => $platformProduct['status'] ?? 'active',
            'external_data'  => json_encode($platformProduct),
            'last_sync'      => now(),
            'is_active'      => ($platformProduct['status'] ?? 'active') === 'active',
        ]);

        // Sauvegarder les images
        if (!empty($platformProduct['images'])) {
            $this->saveProductImages($product->id, $platformProduct['images']);
        }

        // Sauvegarder les variantes si elles existent
        if (!empty($platformProduct['variants']) && is_array($platformProduct['variants'])) {
            // Validation : si le produit principal n'a pas de prix, vérifier que toutes les variantes en ont
            if ($productPrice === null) {
                $this->validateVariantsHavePrices($platformProduct['name'], $platformProduct['variants']);
            }

            $this->saveOrUpdateVariants($product->id, $platformProduct['variants']);
        }

        Log::info('Nouveau produit créé avec variantes', [
            'product_id' => $product->id,
            'external_id' => $product->external_id,
            'name' => $product->name,
            'has_variants' => $hasVariants,
            'variants_count' => $hasVariants ? count($platformProduct['variants']) : 0
        ]);
    }

    /**
     * Met à jour les variantes d'un produit existant
     */
    private function updateProductVariants(int $productId, array $variants): void
    {
        try {
            Log::info('Mise à jour des variantes du produit', [
                'product_id' => $productId,
                'variants_count' => count($variants)
            ]);

            // Utiliser la même logique que saveOrUpdateVariants
            $this->saveOrUpdateVariants($productId, $variants);

            Log::info('Variantes du produit mises à jour avec succès', [
                'product_id' => $productId,
                'variants_count' => count($variants)
            ]);
        } catch (Exception $e) {
            Log::error('Erreur mise à jour variantes produit', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Met à jour les images d'un produit existant
     */
    private function updateProductImages(int $productId, array $images): void
    {
        try {
            // Supprimer les anciennes images
            ProductImage::where('product_id', $productId)->delete();

            // Sauvegarder les nouvelles images
            $this->saveProductImages($productId, $images);

            Log::info('Images du produit mises à jour', [
                'product_id' => $productId,
                'images_count' => count($images)
            ]);
        } catch (Exception $e) {
            Log::error('Erreur mise à jour images produit', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Récupération des détails du vendeur et ses produits
     */
    public function getVendorShop(?User $user, int $vendorId, int $perPage = 12, int $page = 1, string $sortBy = 'name'): array
    {
        // Cache des informations du vendeur (sans données utilisateur)
        $cacheKey = "vendor_shop_info_{$vendorId}";
        $vendorInfo = Cache::remember($cacheKey, 1800, function() use ($vendorId) {
            $vendor = Vendor::with('user:id,first_name,last_name,phone,email,street,postal_code,city')
                ->where('id', $vendorId)
                ->firstOrFail();

            return [
                'id' => $vendor->id,
                'name_store' => $vendor->name_store,
                'siret' => $vendor->siret,
                'website_url' => $vendor->website_url,
                'ecommerce_platform' => $vendor->ecommerce_platform,
                'ecommerce_platform_version' => $vendor->ecommerce_platform_version,
                'name_store' => $vendor->name_store,
                'description' => $vendor->description,
                'subscription_type' => $vendor->subscription_type,
                'subscription_area' => $vendor->subscription_area,
                'is_synchronized' => $vendor->is_synchronized,
                'user' => [
                    'first_name' => $vendor->user->first_name,
                    'last_name' => $vendor->user->last_name,
                    'phone' => $vendor->user->phone,
                    'email' => $vendor->user->email,
                    'street' => $vendor->user->street,
                    'postal_code' => $vendor->user->postal_code,
                    'city' => $vendor->user->city,
                ],
            ];
        });

        // Vérification si l'utilisateur suit ce vendeur
        $isFollowingVendor = false;
        if ($user) {
            $isFollowingVendor = DB::table('vendor_subscriptions')
                ->where('user_id', $user->id)
                ->where('vendor_id', $vendorId)
                ->whereNull('deleted_at') // Gestion du soft delete
                ->exists();
        }

        $vendorInfo['is_following_vendor'] = $isFollowingVendor;

        // Récupération des produits paginés
        $products = $this->getVendorProducts($user, $vendorId, $perPage, $page, $sortBy);

        return [
            'vendor' => $vendorInfo,
            'products' => $products,
        ];
    }

    /**
     * Recherche de produits d'un vendeur spécifique
     */
    public function searchVendorProducts(?User $user, int $vendorId, string $search, int $perPage = 12, int $page = 1, string $sortBy = 'name'): array
    {
        // Vérifier que le vendeur existe
        if (!Vendor::where('id', $vendorId)->exists()) {
            throw new ModelNotFoundException('Vendor not found');
        }

        // Construction de la requête de recherche
        $query = Product::with([
            'category:id,name',
            'images' => function($q) {
                $q->orderBy('is_primary', 'desc')->orderBy('position');
            },
            'variants' => function($q) {
                $q->where('is_active', true)->select('product_id', 'price');
            }
        ])
        ->where('vendor_id', $vendorId)
        ->where('is_active', true)
        ->where('is_blocked', false)
        ->where(function($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%");
        });

        // Application du tri
        $query = $this->applySorting($query, $sortBy);

        // Pagination
        $products = $query->paginate($perPage, ['*'], 'page', $page);

        // Ajout des données spécifiques à l'utilisateur
        $this->addUserSpecificData($user, $products->items());

        return [
            'products' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more_pages' => $products->hasMorePages(),
            ],
            'search_term' => $search,
        ];
    }

    /**
     * Récupération des produits d'un vendeur avec pagination et tri
     */
    private function getVendorProducts(?User $user, int $vendorId, int $perPage, int $page, string $sortBy): array
    {
        $now = Carbon::now();

        // FIX POSTGRESQL : définir les sous-requêtes comme variables
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
        
        // Construction de la requête
        $query = Product::with([
            'category:id,name',
            'images' => function($q) {
                $q->orderBy('is_primary', 'desc')->orderBy('position');
            },
            'variants' => function($q) {
                $q->where('is_active', true)->select('product_id', 'price');
            }
        ])
        ->where('vendor_id', $vendorId)
        ->where('is_active', true)
        ->where('is_blocked', false);

        // Ajouter indicateur si produit en promo/code
        $query->select([
            'products.*',
            DB::raw("{$hasPromotionSubquery} as has_promotion"),
            DB::raw("{$hasPromoCodeSubquery} as has_promo_code"),
        ]);

        // FIX : TOUJOURS trier par promo d'abord avec la sous-requête complète
        $query->orderByRaw("({$hasPromotionSubquery} OR {$hasPromoCodeSubquery}) DESC");

        // Application du tri
        $query = $this->applySorting($query, $sortBy);

        // Pagination
        $products = $query->paginate($perPage, ['*'], 'page', $page);

        // Ajout des données spécifiques à l'utilisateur
        $this->addUserSpecificData($user, $products->items());

        return [
            'products' => $products->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more_pages' => $products->hasMorePages(),
            ],
        ];
    }

    /**
     * Application du tri sur les produits
     */
    private function applySorting($query, string $sortBy)
    {
        switch ($sortBy) {
            case 'price_asc':
                // Tri par prix croissant - FIX: utiliser TRUE au lieu de 1 pour PostgreSQL
                return $query->orderByRaw('
                CASE
                    WHEN price IS NOT NULL THEN price
                    ELSE (
                        SELECT MIN(pv.price)
                        FROM product_variants pv
                        WHERE pv.product_id = products.id
                        AND pv.is_active = TRUE
                        AND pv.deleted_at IS NULL
                    )
                END ASC
            ');

            case 'price_desc':
                // Tri par prix décroissant - FIX: utiliser TRUE au lieu de 1 pour PostgreSQL
                return $query->orderByRaw('
                CASE
                    WHEN price IS NOT NULL THEN price
                    ELSE (
                        SELECT MIN(pv.price)
                        FROM product_variants pv
                        WHERE pv.product_id = products.id
                        AND pv.is_active = TRUE
                        AND pv.deleted_at IS NULL
                    )
                END DESC
            ');

            case 'newest':
            case 'recent':
                // Tri du plus récent au plus ancien (date de création)
                return $query->orderBy('created_at', 'desc');

            case 'oldest':
                // Tri du plus ancien au plus récent
                return $query->orderBy('created_at', 'asc');

            case 'name':
            default:
                // Tri alphabétique avec support des caractères accentués
                // Pour PostgreSQL, on utilise LOWER() pour un tri insensible à la casse
                return $query->orderByRaw('LOWER(name) ASC');
        }
    }

    /**
     * Ajout des données spécifiques à l'utilisateur (favoris, prix calculés)
     */
    private function addUserSpecificData(?User $user, array $products): void
    {
        if (empty($products)) {
            return;
        }

        $productIds = collect($products)->pluck('id')->toArray();

        // Récupération des favoris en une seule requête
        $favoriteIds = [];
        if ($user) {
            $favoriteIds = DB::table('favorites')
                ->whereIn('product_id', $productIds)
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->pluck('product_id')
                ->toArray();
        }

        // Transformation des données pour chaque produit
        foreach ($products as $product) {
            // Calcul du prix d'affichage
            if ($product->price !== null) {
                $product->display_price = $product->price;
            } else {
                // Récupération du prix minimum des variants
                $minPrice = $product->variants->where('price', '!=', null)->min('price');
                $product->display_price = $minPrice;
            }

            // Statut favori
            $product->is_favorite = in_array($product->id, $favoriteIds);

            // Transformation de la catégorie pour n'afficher que le nom
            $product->category_name = $product->category->name ?? null;
            unset($product->category);

            // Masquer les variants dans la réponse (on n'a besoin que du prix)
            unset($product->variants);
        }
    }

    /**
     * Récupération des IDs des produits favoris d'un utilisateur
     */
    private function getFavoriteProductIds(int $userId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return DB::table('favorites')
            ->whereIn('product_id', $productIds)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->pluck('product_id')
            ->toArray();
    }

    /**
     * Trouver un vendeur par l'ID de son utilisateur
     */
    public function findByUserId($userId)
    {
        // Vérifier que l'utilisateur existe et a le rôle vendeur
        $userExists = User::whereHas('roles', function ($query) {
            $query->where('name', 'vendeur');
        })->where('id', $userId)->exists();

        if (!$userExists) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Utilisateur vendeur introuvable');
        }

        // Récupérer le vendor
        $vendor = Vendor::where('user_id', $userId)->first();

        if (!$vendor) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Profil vendeur introuvable pour cet utilisateur');
        }

        return $vendor;
    }

    public function createOrUpdateVendor(array $data): Vendor
    {
        $userId = Auth::id();

        // ⚙️ On cherche le vendeur du user connecté
        $vendor = Vendor::where('user_id', $userId)->first();

        if ($vendor) {
            // Mise à jour des champs non nuls
            $vendor->update(array_filter([
                'name_store' => $data['name_store'] ?? null,
                'siret' => $data['siret'] ?? null,
                'ecommerce_platform' => $data['ecommerce_platform'] ?? null,
                'description' => $data['description'] ?? null,
                'preferred_payment_method' => $data['preferred_payment_method'] ?? 'stripe',
            ], fn($value) => !is_null($value))); // ignore les nulls

            return $vendor->fresh();
        }

        // Sinon, création du vendeur
        return Vendor::create([
            'user_id' => $userId,
            'name_store' => $data['name_store'] ?? null,
            'siret' => $data['siret'] ?? null,
            'ecommerce_platform' => $data['ecommerce_platform'] ?? null,
            'description' => $data['description'] ?? null,
            'preferred_payment_method' => $data['preferred_payment_method'] ?? 'stripe',
        ]);
    }

    /**
     * Mettre à jour les informations de la boutique (vendor) et de l'utilisateur
     * 
     * @param int $userId ID de l'utilisateur
     * @param array $validated Données validées
     * @return array ['vendor' => Vendor, 'user' => User]
     * @throws ModelNotFoundException Si le vendeur n'existe pas
     * @throws \Exception Si l'URL du site web est déjà utilisée
     */
    public function updateVendorInfo(int $userId, array $validated): array
    {
        $vendor = Vendor::where('user_id', $userId)->first();

        if (!$vendor) {
            throw new ModelNotFoundException('Vendeur introuvable');
        }

        // Vérifier l'unicité du website_url si fourni
        if (isset($validated['website_url']) && $validated['website_url']) {
            $existingVendor = Vendor::where('website_url', $validated['website_url'])
                ->where('id', '!=', $vendor->id)
                ->first();
            
            if ($existingVendor) {
                throw new \Exception('Cette URL de boutique est déjà utilisée par un autre vendeur');
            }
        }

        // Séparer les données vendor et user
        $vendorData = array_filter([
            'name_store' => $validated['name_store'] ?? null,
            'description' => $validated['description'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'phone_number_store' => $validated['phone_number_store'] ?? null,
            'landline_phone_store' => $validated['landline_phone_store'] ?? null,
        ], fn($value) => !is_null($value));

        $userData = array_filter([
            'street' => $validated['street'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
        ], fn($value) => !is_null($value));

        // Mettre à jour le vendor
        if (!empty($vendorData)) {
            $vendor->update($vendorData);
        }

        // Mettre à jour l'utilisateur
        if (!empty($userData)) {
            $vendor->user->update($userData);
        }

        return [
            'vendor' => $vendor->fresh(),
            'user' => $vendor->user->fresh()->load('vendor')
        ];
    }
}
