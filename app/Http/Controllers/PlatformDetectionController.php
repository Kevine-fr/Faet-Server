<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Vendor;
use App\Models\Product;
use App\Services\VendorService;

class PlatformDetectionController extends Controller
{
    /**
     * Détecte automatiquement la plateforme e-commerce d'un site web
     */
    public function detect(Request $request): JsonResponse
    {
        try {
            // Log pour déboguer
            Log::info('Détection de plateforme - requête reçue', [
                'body' => $request->all(),
                'headers' => $request->headers->all()
            ]);

            // Validation plus souple pour l'URL
            $request->validate([
                'websiteUrl' => 'required|string'
            ]);

            $websiteUrl = $request->input('websiteUrl');
            
            // Normaliser l'URL
            if (!str_starts_with($websiteUrl, 'https://')) {
                if (str_starts_with($websiteUrl, 'http://')) {
                    $websiteUrl = str_replace('http://', 'https://', $websiteUrl);
                } else {
                    $websiteUrl = 'https://' . $websiteUrl;
                }
            }

            Log::info("Détection de plateforme pour: {$websiteUrl}");

            // Test simple pour vérifier que l'API fonctionne
            if ($websiteUrl === 'test') {
                return response()->json([
                    'success' => true,
                    'detection' => [
                        'platform' => 'test',
                        'confidence' => 100,
                        'indicators' => ['Test réussi'],
                        'message' => 'Test API réussi'
                    ]
                ]);
            }

            // Vérifier que l'URL est valide avant de faire la requête
            if (!filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'success' => false,
                    'error' => 'URL invalide',
                    'details' => 'Format d\'URL non reconnu'
                ], 400);
            }

            // Faire une requête HTTP pour analyser le site (comme dans le projet React)
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; FFOVBot/1.0)'
                ])
                ->get($websiteUrl);

            if (!$response->successful()) {
                return response()->json([
                    'success' => true,
                    'detection' => [
                        'platform' => 'unknown',
                        'confidence' => 0,
                        'indicators' => ['Site non accessible'],
                        'message' => 'Plateforme non détectée automatiquement'
                    ]
                ]);
            }

            $html = $response->body();
            $headers = $response->headers();
            
            $indicators = [];
            $platform = 'unknown';
            $confidence = 0;

            // Détection Shopify (exactement comme dans le projet React)
            if (
                str_contains($html, 'Shopify.shop') ||
                str_contains($html, 'shopify-section') ||
                str_contains($html, 'cdn.shopify.com') ||
                str_contains($headers['server'] ?? '', 'Shopify') ||
                str_contains($websiteUrl, '.myshopify.com')
            ) {
                $platform = 'shopify';
                $confidence = 90;
                $indicators[] = 'Shopify.shop détecté';
                $indicators[] = 'CDN Shopify détecté';
            }
            
            elseif (
                str_contains($html, 'woocommerce') ||
                str_contains($html, 'wp-content') ||
                str_contains($html, 'wp-json/wc/') ||
                str_contains($html, 'wc-block') ||
                str_contains($html, 'class="woocommerce')
            ) {
                $platform = 'woocommerce';
                $confidence = 85;
                $indicators[] = 'WooCommerce classes détectées';
                $indicators[] = 'WordPress détecté';
            }
            
            elseif (
                str_contains($html, 'PrestaShop') ||
                str_contains($html, 'prestashop') ||
                str_contains($html, '/modules/') ||
                str_contains($html, 'ps_') ||
                str_contains($html, 'prestashop.min.js')
            ) {
                $platform = 'prestashop';
                $confidence = 80;
                $indicators[] = 'PrestaShop détecté';
                $indicators[] = 'Modules PrestaShop détectés';
            }
            
            elseif (
                str_contains($html, 'Magento') ||
                str_contains($html, 'magento') ||
                str_contains($html, 'Mage.') ||
                str_contains($html, 'mage/') ||
                str_contains($html, 'var/cache/mage') ||
                str_contains($html, 'skin/frontend') ||
                str_contains($html, 'static/_requirejs') ||
                ($headers['x-magento-vary'] ?? false) ||
                ($headers['x-magento-tags'] ?? false)
            ) {
                $platform = 'magento';
                $confidence = 85;
                $indicators[] = 'Magento détecté';
                $indicators[] = 'Scripts Magento détectés';
            }

            // Détections supplémentaires basées sur les meta tags
            if (preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                $generator = strtolower($matches[1]);
                if (str_contains($generator, 'shopify')) {
                    $platform = 'shopify';
                    $confidence = max($confidence, 95);
                    $indicators[] = 'Meta generator Shopify';
                } elseif (str_contains($generator, 'woocommerce') || str_contains($generator, 'wordpress')) {
                    $platform = 'woocommerce';
                    $confidence = max($confidence, 90);
                    $indicators[] = 'Meta generator WordPress/WooCommerce';
                } elseif (str_contains($generator, 'prestashop')) {
                    $platform = 'prestashop';
                    $confidence = max($confidence, 90);
                    $indicators[] = 'Meta generator PrestaShop';
                } elseif (str_contains($generator, 'magento')) {
                    $platform = 'magento';
                    $confidence = max($confidence, 90);
                    $indicators[] = 'Meta generator Magento';
                }
            }

            // Si aucune plateforme détectée, chercher des indicateurs génériques
            if ($platform === 'unknown') {
                $ecommerceIndicators = [
                    'add to cart', 'ajouter au panier', 'shopping cart', 'panier',
                    'checkout', 'commander', 'buy now', 'acheter',
                    'product', 'produit', 'price', 'prix'
                ];
                
                $foundIndicators = array_filter($ecommerceIndicators, function($indicator) use ($html) {
                    return str_contains(strtolower($html), strtolower($indicator));
                });
                
                if (!empty($foundIndicators)) {
                    $platform = 'other';
                    $confidence = 60 + (count($foundIndicators) * 5);
                    $indicators[] = count($foundIndicators) . ' indicateurs e-commerce détectés';
                    foreach ($foundIndicators as $indicator) {
                        $indicators[] = "\"{$indicator}\" trouvé";
                    }
                }
            }

            Log::info("Plateforme détectée: {$platform} (confiance: {$confidence}%)");

            return response()->json([
                'success' => true,
                'detection' => [
                    'platform' => $platform,
                    'confidence' => min($confidence, 100),
                    'indicators' => $indicators,
                    'message' => $platform !== 'unknown' 
                        ? ucfirst($platform) . " détecté (" . min($confidence, 100) . "% de confiance)"
                        : 'Aucun site e-commerce détecté'
                ],
                'suggestedConfig' => $this->generateSuggestedConfig($platform, $websiteUrl)
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la détection de plateforme:', [
                'error' => $e->getMessage(),
                'url' => $request->input('websiteUrl')
            ]);

            return response()->json([
                'success' => true,
                'detection' => [
                    'platform' => 'unknown',
                    'confidence' => 0,
                    'indicators' => ['Erreur de connexion'],
                    'message' => 'Plateforme non détectée automatiquement'
                ]
            ]);
        }
    }

    /**
     * Génère une configuration suggérée basée sur la plateforme détectée
     */
    private function generateSuggestedConfig(string $platform, string $websiteUrl): array
    {
        $config = [];

        switch ($platform) {
            case 'shopify':
                $config = [
                    'platform' => 'shopify',
                    'shopUrl' => $websiteUrl,
                    'shopName' => $this->extractShopName($websiteUrl),
                    'apiVersion' => '2024-01'
                ];
                break;

            case 'woocommerce':
                $config = [
                    'platform' => 'woocommerce',
                    'shopUrl' => $websiteUrl,
                    'apiVersion' => 'v3'
                ];
                break;

            case 'prestashop':
                $config = [
                    'platform' => 'prestashop',
                    'shopUrl' => $websiteUrl
                ];
                break;

            case 'magento':
                $config = [
                    'platform' => 'magento',
                    'shopUrl' => $websiteUrl,
                    'apiVersion' => 'V1'
                ];
                break;

            default:
                $config = [
                    'platform' => $platform,
                    'shopUrl' => $websiteUrl
                ];
        }

        return $config;
    }



    /**
     * Sauvegarde la configuration e-commerce d'un vendeur
     */
    public function saveEcommerceConfig(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $request->validate([
                'platform' => 'required|string|in:shopify,woocommerce,prestashop,magento',
                'shop_url' => 'required|string',
                'shop_name' => 'nullable|string',
                'access_token' => 'required|string',
                'api_version' => 'nullable|string',
            ]);

            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            Log::info('Utilisateur connecté:', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            $vendorId = $user->vendor->id ?? null;
            Log::info('Vendor ID récupéré:', [
                'vendor_id' => $vendorId
            ]);
            
            if (!$vendorId) {
                Log::error('Vendeur non trouvé pour l\'utilisateur:', [
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Préparer la configuration
            $config = [
                'platform' => $request->input('platform'),
                'shop_url' => $request->input('shop_url'),
                'shop_name' => $request->input('shop_name'),
                'access_token' => $request->input('access_token'),
                'api_version' => $request->input('api_version'),
            ];

            // Utiliser le VendorService pour sauvegarder
            $vendorService = new VendorService();
            $result = $vendorService->saveEcommerceConfig($vendorId, $config);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'products_count' => $result['products_count'] ?? 0
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error']
                ], 400);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Les données saisies sont invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur sauvegarde config e-commerce:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la sauvegarde: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les produits du vendeur
     */
    public function getVendorProducts(Request $request): JsonResponse
    {
        try {
            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            $vendorId = $user->vendor->id ?? null;
            
            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Récupérer le nombre total de produits synchronisés (sans recherche)
            $totalProducts = Product::where('vendor_id', $vendorId)->count();
            
            // Récupérer les produits du vendeur avec pagination et recherche
            $query = Product::where('vendor_id', $vendorId);
            
            // Recherche par nom ou SKU
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('sku', 'LIKE', "%{$search}%");
                });
            }
            
            $products = $query->with(['images','variants'])->orderBy('created_at', 'desc')->paginate(10)
                ->through(function ($product) {
                    // Calculer le prix minimum des variantes si le prix du produit est null
                    $displayPrice = $product->price;
                    if ($displayPrice === null) {
                        $minVariantPrice = $product->variants()
                            ->whereNotNull('price')
                            ->where('price', '>', 0)
                            ->min('price');
                        
                        if ($minVariantPrice !== null) {
                            $displayPrice = $minVariantPrice;
                        }
                    }

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'price' => $displayPrice,
                        'stock' => $product->stock_quantity,
                        'status' => $product->is_active ? 'active' : 'inactive',
                        'last_sync' => $product->last_sync,
                        'external_id' => $product->external_id,
                        'description' => $product->description,
                        'images' => $product->images->map(function($image) {
                            return [
                                'id' => $image->id,
                                'path' => $image->path,
                                'alt' => $image->alt,
                                'is_primary' => $image->is_primary,
                                'position' => $image->position
                            ];
                        })->toArray(),
                        'variants' => $product->variants->map(function($variant) {
                            return [
                                'id' => $variant->id,
                                'external_id' => $variant->external_id,
                                'sku' => $variant->sku,
                                'price' => $variant->price,
                                'stock_quantity' => $variant->stock_quantity,
                                'size' => $variant->size,
                                'color' => $variant->color,
                                'attributes' => $variant->attributes,
                                'is_active' => (bool) $variant->is_active,
                            ];
                        })->toArray(),
                    ];
                });

            return response()->json([
                'success' => true,
                'products' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'has_more_pages' => $products->hasMorePages(),
                    'has_previous_pages' => $products->previousPageUrl() !== null
                ],
                'total_synchronized' => $totalProducts,
                'count' => $products->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération produits vendeur:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des produits'
            ], 500);
        }
    }

    /**
     * Récupère le statut de la plateforme connectée
     */
    public function getPlatformStatus(Request $request): JsonResponse
    {
        try {
            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            $vendorId = $user->vendor->id ?? null;
            
            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Récupérer les informations du vendeur
            $vendor = \App\Models\Vendor::find($vendorId);
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Vérifier si une plateforme est configurée
            $platform = $vendor->ecommerce_platform;
            $connected = !empty($platform);

            return response()->json([
                'success' => true,
                'connected' => $connected,
                'platform' => $platform,
                'shop_url' => $vendor->website_url,
                'shop_name' => $vendor->name_store,
                'last_sync' => $vendor->last_synced_at,
                'config' => [
                    'website_url' => $vendor->website_url,
                    'platform' => $vendor->ecommerce_platform,
                    'store_name' => $vendor->name_store
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération statut plateforme:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération du statut'
            ], 500);
        }
    }

    /**
     * Extrait le nom de la boutique depuis l'URL Shopify
     */
    private function extractShopName(string $url): string
    {
        if (str_contains($url, '.myshopify.com')) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? '';
            return str_replace('.myshopify.com', '', $host);
        }

        return '';
    }
} 