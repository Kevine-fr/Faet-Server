<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PromotionProductService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PromotionProductController extends Controller
{
    protected PromotionProductService $promotionProductService;

    public function __construct(PromotionProductService $promotionProductService)
    {
        $this->promotionProductService = $promotionProductService;
    }

    /**
     * Liste toutes les associations promotion-produit du vendeur connecté
     */
    public function index(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotionProducts = $this->promotionProductService->listPromotionProducts($vendorId);
            
            return response()->json([
                'success' => true,
                'data' => $promotionProducts
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des associations: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des associations'
            ], 500);
        }
    }

    /**
     * Récupérer une association spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotionProduct = $this->promotionProductService->getPromotionProduct($id, $vendorId);
            
            if (!$promotionProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Association non trouvée'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $promotionProduct
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de l\'association: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'association'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle association promotion-produit
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();
            
            $promotionProduct = $this->promotionProductService->createPromotionProduct($data, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Association créée avec succès',
                'data' => $promotionProduct
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de l\'association: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'association: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour une association promotion-produit
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();
            
            $promotionProduct = $this->promotionProductService->updatePromotionProduct($id, $data, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Association mise à jour avec succès',
                'data' => $promotionProduct
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'association: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une association promotion-produit
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            
            $this->promotionProductService->deletePromotionProduct($id, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Association supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de l\'association: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer tous les produits d'une promotion
     */
    public function getByPromotion(int $promotionId): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $products = $this->promotionProductService->getPromotionProducts($promotionId, $vendorId);
            
            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des produits de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Récupérer toutes les promotions d'un produit
     */
    public function getByProduct(int $productId): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotions = $this->promotionProductService->getProductPromotions($productId, $vendorId);
            
            return response()->json([
                'success' => true,
                'data' => $promotions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des promotions du produit: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Ajouter des produits à une promotion
     */
    public function attach(Request $request, int $promotionId): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            
            $request->validate([
                'product_ids' => 'required|array',
                'product_ids.*' => 'integer|exists:products,id'
            ]);

            $result = $this->promotionProductService->attachProductsToPromotion(
                $promotionId,
                $request->input('product_ids'),
                $vendorId
            );
            
            return response()->json([
                'success' => true,
                'message' => count($result['attached']) . ' produit(s) ajouté(s) à la promotion',
                'data' => $result
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'ajout des produits à la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retirer des produits d'une promotion
     */
    public function detach(Request $request, int $promotionId): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            
            $request->validate([
                'product_ids' => 'required|array',
                'product_ids.*' => 'integer|exists:products,id'
            ]);

            $result = $this->promotionProductService->detachProductsFromPromotion(
                $promotionId,
                $request->input('product_ids'),
                $vendorId
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Produit(s) retiré(s) de la promotion',
                'data' => $result
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors du retrait des produits de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchroniser les produits d'une promotion (remplace tous les produits)
     */
    public function sync(Request $request, int $promotionId): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            
            $request->validate([
                'product_ids' => 'nullable|array',
                'product_ids.*' => 'integer|exists:products,id'
            ]);

            $result = $this->promotionProductService->syncPromotionProducts(
                $promotionId,
                $request->input('product_ids', []),
                $vendorId
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Produits de la promotion synchronisés avec succès',
                'data' => $result
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la synchronisation des produits de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
