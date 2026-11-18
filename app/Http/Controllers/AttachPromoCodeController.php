<?php

namespace App\Http\Controllers;

use App\Services\AttachPromoCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AttachPromoCodeController extends Controller
{
    protected AttachPromoCodeService $attachPromoCodeService;

    public function __construct(AttachPromoCodeService $attachPromoCodeService)
    {
        $this->attachPromoCodeService = $attachPromoCodeService;
    }

    /**
     * Attacher des produits à un code promo
     */
    public function attachProducts(Request $request): JsonResponse
    {
        try {
            Log::info('AttachPromoCodeController: Début de l\'attachement', [
                'request_data' => $request->all()
            ]);

            $validator = Validator::make($request->all(), [
                'promo_code_id' => 'required|integer|exists:promo_codes,id',
                'product_ids' => 'required|array|min:1',
                'product_ids.*' => 'integer|exists:products,id'
            ]);

            if ($validator->fails()) {
                Log::warning('AttachPromoCodeController: Validation échouée', [
                    'errors' => $validator->errors()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $promoCodeId = $request->input('promo_code_id');
            $productIds = $request->input('product_ids');

            Log::info('AttachPromoCodeController: Données validées', [
                'promo_code_id' => $promoCodeId,
                'product_ids' => $productIds
            ]);

            // Vérifier que l'utilisateur est le propriétaire du code promo
            $user = Auth::user();
            Log::info('AttachPromoCodeController: Utilisateur connecté', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);

            if (!$user->vendor) {
                Log::error('AttachPromoCodeController: Utilisateur sans vendeur', [
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non autorisé - pas de vendeur associé'
                ], 403);
            }

            Log::info('AttachPromoCodeController: Vendeur trouvé', [
                'vendor_id' => $user->vendor->id,
                'vendor_name' => $user->vendor->name_store
            ]);

            $promoCode = $user->vendor->promoCodes()->find($promoCodeId);
            
            if (!$promoCode) {
                Log::warning('AttachPromoCodeController: Code promo non trouvé ou non autorisé', [
                    'promo_code_id' => $promoCodeId,
                    'vendor_id' => $user->vendor->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Code promo non trouvé ou accès non autorisé'
                ], 404);
            }

            Log::info('AttachPromoCodeController: Code promo autorisé', [
                'promo_code' => $promoCode->toArray()
            ]);

            // Attacher les produits
            $attachedProducts = $this->attachPromoCodeService->attachProducts($promoCodeId, $productIds);

            Log::info('AttachPromoCodeController: Produits attachés avec succès', [
                'attached_products' => $attachedProducts
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Produits attachés avec succès',
                'data' => [
                    'promo_code' => [
                        'id' => $promoCode->id,
                        'code' => $promoCode->code,
                        'name' => $promoCode->name,
                        'type' => $promoCode->type,
                        'value' => $promoCode->value
                    ],
                    'attached_products' => $attachedProducts,
                    'total_products' => count($attachedProducts)
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('AttachPromoCodeController: Erreur lors de l\'attachement des produits', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'attachement des produits'
            ], 500);
        }
    }

    /**
     * Détacher un produit d'un code promo
     */
    public function detachProduct(Request $request, int $promoCodeId, int $productId): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est le propriétaire du code promo
            $user = Auth::user();
            $promoCode = $user->vendor->promoCodes()->find($promoCodeId);
            
            if (!$promoCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code promo non trouvé ou accès non autorisé'
                ], 404);
            }

            // Détacher le produit
            $deleted = $this->attachPromoCodeService->detachProduct($promoCodeId, $productId);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé ou déjà détaché'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Produit détaché avec succès',
                'data' => [
                    'promo_code_id' => $promoCodeId,
                    'product_id' => $productId
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors du détachement du produit: ' . $e->getMessage(), [
                'promo_code_id' => $promoCodeId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du détachement du produit'
            ], 500);
        }
    }

    /**
     * Récupérer les produits attachés à un code promo
     */
    public function getAttachedProducts(int $promoCodeId): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est le propriétaire du code promo
            $user = Auth::user();
            $promoCode = $user->vendor->promoCodes()->find($promoCodeId);
            
            if (!$promoCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code promo non trouvé ou accès non autorisé'
                ], 404);
            }

            // Récupérer les produits attachés
            $attachedProducts = $this->attachPromoCodeService->getAttachedProducts($promoCodeId);

            return response()->json([
                'success' => true,
                'data' => $attachedProducts
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des produits attachés: ' . $e->getMessage(), [
                'promo_code_id' => $promoCodeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits attachés'
            ], 500);
        }
    }

    /**
     * Récupérer tous les produits déjà attachés à n'importe quel code promo du vendeur
     */
    public function getAllAttachedProducts(): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est un vendeur
            $user = Auth::user();
            
            if (!$user->vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non autorisé - pas de vendeur associé'
                ], 403);
            }

            // Récupérer tous les produits déjà attachés
            $attachedProducts = $this->attachPromoCodeService->getAllAttachedProducts($user->vendor->id);

            return response()->json([
                'success' => true,
                'data' => $attachedProducts
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de tous les produits attachés: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits attachés'
            ], 500);
        }
    }

    /**
     * Calculer le prix final d'un produit avec un code promo (pour prévisualisation)
     */
    public function calculateFinalPrice(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'promo_code_id' => 'required|integer|exists:promo_codes,id',
                'product_id' => 'required|integer|exists:products,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $promoCodeId = $request->input('promo_code_id');
            $productId = $request->input('product_id');

            // Vérifier que l'utilisateur est le propriétaire du code promo
            $user = Auth::user();
            $promoCode = $user->vendor->promoCodes()->find($promoCodeId);
            
            if (!$promoCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code promo non trouvé ou accès non autorisé'
                ], 404);
            }

            // Récupérer le produit
            $product = $user->vendor->products()->find($productId);
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé ou accès non autorisé'
                ], 404);
            }

            // Calculer le prix final
            $finalPrice = $this->attachPromoCodeService->calculateFinalPrice(
                $product->price, 
                $promoCode->type, 
                $promoCode->value
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'original_price' => $product->price
                    ],
                    'promo_code' => [
                        'id' => $promoCode->id,
                        'code' => $promoCode->code,
                        'name' => $promoCode->name,
                        'type' => $promoCode->type,
                        'value' => $promoCode->value
                    ],
                    'final_price' => $finalPrice,
                    'discount_amount' => $product->price - $finalPrice,
                    'discount_percentage' => $promoCode->type === 'percentage' 
                        ? $promoCode->value 
                        : round((($product->price - $finalPrice) / $product->price) * 100, 2)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul du prix final: ' . $e->getMessage(), [
                'request' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul du prix final'
            ], 500);
        }
    }
}
