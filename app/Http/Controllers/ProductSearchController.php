<?php

namespace App\Http\Controllers;

use App\Services\ProductSearchService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __construct(
        private ProductSearchService $searchService
    ) {}

    /**
     * Recherche de produits
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:100',
            'limit' => 'sometimes|integer|min:1|max:50',
            'category_id' => 'sometimes|integer|exists:categories,id',
            'min_price' => 'sometimes|numeric|min:0',
            'max_price' => 'sometimes|numeric|min:0',
        ]);

        try {
            // Recherche simple ou avancée
            if (isset($validated['category_id']) || isset($validated['min_price']) || isset($validated['max_price'])) {
                $results = $this->searchService->advancedSearch(
                    $validated['q'],
                    $validated['category_id'] ?? null,
                    $validated['min_price'] ?? null,
                    $validated['max_price'] ?? null,
                    $validated['limit'] ?? 20
                );
            } else {
                $results = $this->searchService->search(
                    $validated['q'],
                    $validated['limit'] ?? 20
                );
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'count' => $results->count(),
                'query' => $validated['q']
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Produits populaires basés sur les recherches
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function popular(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:50'
        ]);

        try {
            $products = $this->searchService->getPopularProducts(
                $validated['limit'] ?? 10
            );

            return response()->json([
                'success' => true,
                'data' => $products,
                'count' => $products->count()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits populaires',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Suggestions de recherche (autocomplete)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'limit' => 'sometimes|integer|min:1|max:10'
        ]);

        try {
            $results = $this->searchService->search(
                $validated['q'],
                $validated['limit'] ?? 5
            );

            // Retourner uniquement les noms pour l'autocomplete
            $suggestions = $results->map(fn($item) => [
                'id' => $item['id'],
                'name' => $item['name'],
                'category' => $item['category'],
                'image' => $item['image']
            ]);

            return response()->json([
                'success' => true,
                'data' => $suggestions
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des suggestions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
