<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\VendorService;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SynchronizationController extends Controller
{
    protected $vendorService;

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    /**
     * Tester la connexion avec la boutique e-commerce
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'website_url' => 'required|url',
            'ecommerce_platform' => 'required|string',
            'access_token' => 'required|string',
            'api_version' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        $connectionData = $request->only([
            'website_url',
            'ecommerce_platform',
            'access_token',
            'api_version'
        ]);

        $result = $this->vendorService->testConnection($connectionData['ecommerce_platform'], $connectionData);

        return response()->json($result);
    }

    /**
     * Vérifier si un website_url est disponible (unique)
     */
    public function checkWebsiteUrlAvailability(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'website_url' => 'required|url',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'available' => false,
                    'message' => 'URL invalide',
                    'errors' => $validator->errors()
                ], 422);
            }

            $websiteUrl = $request->input('website_url');
            $user = Auth::user();
            $vendorId = $user && $user->vendor ? $user->vendor->id : null;

            // Vérifier si l'URL est déjà utilisée par un autre vendeur
            $existingVendor = Vendor::where('website_url', $websiteUrl);
            
            // Exclure le vendeur actuel si on est en train de mettre à jour
            if ($vendorId) {
                $existingVendor->where('id', '!=', $vendorId);
            }
            
            $existingVendor = $existingVendor->first();

            if ($existingVendor) {
                return response()->json([
                    'available' => false,
                    'message' => 'Cette URL de boutique est déjà utilisée par un autre vendeur'
                ]);
            }

            return response()->json([
                'available' => true,
                'message' => 'URL disponible'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur vérification disponibilité website_url', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'available' => false,
                'message' => 'Erreur lors de la vérification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchroniser la boutique du vendeur
     */
    public function synchronizeShop(Request $request): JsonResponse
    {
        // Vérifier que l'utilisateur est un vendeur
        if (Auth::user()->role !== 'vendeur') {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé. Vendeur requis.'
            ], 403);
        }

        try {
            // Récupérer l'ID du vendeur
            $vendorId = Auth::user()->vendor->id;
            
            // Valider avec unique en excluant le vendor actuel
            $validator = Validator::make($request->all(), [
                'website_url' => [
                    'required',
                    'url',
                    'unique:vendors,website_url,' . $vendorId
                ],
                'ecommerce_platform' => 'required|string',
                'access_token' => 'required|string',
                'api_version' => 'nullable|string',
                'name_store' => 'nullable|string',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Préparer les données de la boutique et les mapper correctement
            $shopData = [
                'platform' => $request->input('ecommerce_platform'),
                'shop_url' => $request->input('website_url'),
                'access_token' => $request->input('access_token'),
                'api_version' => $request->input('api_version'),
                'shop_name' => $request->input('name_store'),
                'description' => $request->input('description'),
            ];

            // Effectuer la synchronisation
            $result = $this->vendorService->saveEcommerceConfig($vendorId, $shopData);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Boutique synchronisée avec succès !',
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir le statut de synchronisation du vendeur
     */
    public function getSyncStatus(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Log pour déboguer
            Log::info('getSyncStatus - Utilisateur connecté', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'has_vendor' => $user->vendor ? 'oui' : 'non'
            ]);
            
            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                Log::warning('getSyncStatus - Pas de profil vendeur', [
                    'user_id' => $user->id,
                    'user_email' => $user->email
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }
            
            Log::info('getSyncStatus - Profil vendeur trouvé', [
                'vendor_id' => $vendor->id,
                'name_store' => $vendor->name_store,
                'ecommerce_platform' => $vendor->ecommerce_platform
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'is_synchronized' => !empty($vendor->ecommerce_platform),
                    'last_synced_at' => $vendor->last_synced_at,
                    'ecommerce_platform' => $vendor->ecommerce_platform,
                    'name_store' => $vendor->name_store,
                    'website_url' => $vendor->website_url,
                    'products_count' => $vendor->products()->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchroniser les images d'un produit spécifique
     */
    public function syncProductImages(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $productId = $request->input('product_id');
            
            // Vérifier que le produit appartient au vendeur connecté
            $product = \App\Models\Product::where('id', $productId)
                ->where('vendor_id', $vendor->id)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé ou accès non autorisé'
                ], 404);
            }

            // Utiliser le VendorService pour synchroniser les images
            $result = $this->vendorService->syncProductImages($productId);

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchroniser les images de tous les produits du vendeur
     */
    public function syncAllProductImages(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Vérifier si l'utilisateur a un profil vendeur
            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé - Profil vendeur requis'
                ], 403);
            }
            
            // Récupérer tous les produits du vendeur
            $products = \App\Models\Product::where('vendor_id', $vendor->id)
                ->whereNotNull('external_id')
                ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun produit trouvé pour ce vendeur'
                ], 404);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($products as $product) {
                try {
                    $result = $this->vendorService->syncProductImages($product->id);
                    $results[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'success' => $result['success'],
                        'message' => $result['message'] ?? $result['error'] ?? 'Synchronisation terminée'
                    ];

                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'success' => false,
                        'message' => 'Erreur: ' . $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Synchronisation terminée: {$successCount} succès, {$errorCount} échecs",
                'data' => [
                    'total_products' => $products->count(),
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'results' => $results
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation des images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchronisation intelligente qui préserve les modifications locales
     */
    public function smartSync(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé. Vendeur requis.'
                ], 403);
            }

            // Récupérer la configuration actuelle de la boutique
            $shopConfig = [
                'platform' => $vendor->ecommerce_platform,
                'shop_url' => $vendor->website_url,
                'access_token' => $vendor->getDecryptedAccessToken(),
                'api_version' => $vendor->api_version ?? '2024-01'
            ];

            // Vérifier que la configuration est complète
            if (empty($shopConfig['platform']) || empty($shopConfig['shop_url']) || empty($shopConfig['access_token'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuration de boutique incomplète. Veuillez d\'abord configurer votre boutique.'
                ], 400);
            }

            // Effectuer la synchronisation intelligente (restaure aussi les produits soft-deleted)
            $result = $this->vendorService->smartSyncProducts($vendor->id, $shopConfig);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => $result
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'error' => $result['error'] ?? null
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Erreur synchronisation intelligente', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la synchronisation intelligente',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
