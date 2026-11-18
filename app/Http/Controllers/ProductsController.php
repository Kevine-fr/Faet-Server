<?php

namespace App\Http\Controllers;

use App\Models\products;
use Illuminate\Http\Request;
use App\Services\ProductService;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class ProductsController extends Controller
{
    protected ProductService $productService;
    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(products $products)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(products $products)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, products $products)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(products $products)
    {
        //
    }

    public function clientGetProducts(Request $request)
    {
        try {
            $user = auth()->user();
            $categoriesCount = 5;
            $productsPerCategory = 6;
            $audienceId = $request->input('audience_id');

            $result = $this->productService->getHomeCategoriesWithProducts(
                $user,
                $categoriesCount,
                $productsPerCategory,
                $audienceId
            );

            if (!$result['has_vendors']) {
                return response()->json([
                    'message' => $result['message'],
                    'success' => true,
                    'error' => false,
                    'data' => [
                        'categories' => [],
                        'has_vendors' => false
                    ]
                ], 200);
            }

            return response()->json([
                'message' => 'Catégories avec produits récupérées avec succès',
                'success' => true,
                'error' => false,
                'data' => [
                    'categories' => $result['categories'],
                    'has_vendors' => true,
                    'filter_mode' => $result['filter_mode'],
                    'user_city' => $result['user_city']
                ]
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des catégories avec produits',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère 6 nouveaux produits (7 derniers jours)
     * 
     * GET /shop/products/new
     */
    public function getNewProducts(Request $request)
    {
        try {
            $user = auth()->user();
            $limit = 6;

            $payload = $this->productService->getNewProducts($user, $limit);

            // Cas spécial : aucun vendeur dans la ville
            if (!$payload['has_vendors']) {
                return response()->json([
                    'message' => $payload['message'],
                    'success' => true,
                    'error' => false,
                    'data' => [
                        'products' => [],
                        'has_vendors' => false
                    ]
                ], 200);
            }

            return response()->json([
                'message' => 'Nouveaux produits récupérés avec succès',
                'success' => true,
                'error' => false,
                'data' => $payload
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des nouveaux produits',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère 6 produits en promotion
     * 
     * GET /shop/products/promotions
     */
    public function getPromotionProducts(Request $request)
    {
        try {
            $user = auth()->user();
            $limit = 6;

            $payload = $this->productService->getPromotionProducts($user, $limit);

            // Cas spécial : aucun vendeur dans la ville
            if (!$payload['has_vendors']) {
                return response()->json([
                    'message' => $payload['message'],
                    'success' => true,
                    'error' => false,
                    'data' => [
                        'products' => [],
                        'has_vendors' => false
                    ]
                ], 200);
            }

            return response()->json([
                'message' => 'Produits en promotion récupérés avec succès',
                'success' => true,
                'error' => false,
                'data' => $payload
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des produits en promotion',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère un produit par id avec ses relations et l'attribut is_favorite si l'utilisateur est connecté.
     *
     * GET /shop/products/{id}
     */
    public function clientGetProduct(Request $request, $id)
    {
        try {
            $user = auth()->user(); // peut être null si pas connecté

            // Accepter ID ou hash
            $product = $this->productService->getProductDetails($user, $id);

            return response()->json([
                'success' => true,
                'data' => $product,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Produit introuvable.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la récupération du produit.",
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère des produits similaires par catégorie.
     *
     * POST /shop/products/similar
     */
    public function getSimilarProducts(Request $request)
    {
        try {
            $request->validate([
                'category_id' => 'required|integer|exists:categories,id'
            ]);

            $user = auth()->user();
            $categoryId = $request->input('category_id');
            $subcategoriesCount = 2;
            $productsPerSubcategory = 6;

            $payload = $this->productService->getSimilarProductsByCategory(
                $user,
                $categoryId,
                $subcategoriesCount,
                $productsPerSubcategory
            );

            // Cas spécial : aucun vendeur dans la ville
            if (!$payload['has_vendors']) {
                return response()->json([
                    'message' => $payload['message'],
                    'success' => true,
                    'error' => false,
                    'data' => [
                        'subcategories' => [],
                        'has_vendors' => false
                    ]
                ], 200);
            }

            return response()->json([
                'message' => 'Produits similaires récupérés avec succès',
                'success' => true,
                'error' => false,
                'data' => $payload
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Données invalides',
                'success' => false,
                'error' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des produits similaires',
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
