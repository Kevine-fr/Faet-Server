<?php

namespace App\Http\Controllers;

use App\Services\TitlePromotionService;
use App\Models\TitlePromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class TitlePromotionController extends Controller
{
    protected $service;

    public function __construct(TitlePromotionService $service)
    {
        $this->service = $service;
    }

public function index(Request $request): JsonResponse
{
    try {
        $page    = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 20);

        $query = TitlePromotion::query();

        // --- SEARCH ---
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // --- FILTRE STATUS ---
        if ($request->filled('status')) {
            $now = now();

            switch ($request->status) {
                case 'active':
                    $query->where('start_date', '<=', $now)
                          ->where('end_date', '>=', $now);
                    break;

                case 'upcoming':
                    $query->where('start_date', '>', $now);
                    break;

                case 'expired':
                    $query->where('end_date', '<', $now);
                    break;
            }
        }

        // 🔥 TRIER DU PLUS RÉCENT AU PLUS ANCIEN
        $query->orderBy('created_at', 'desc');

        // PAGINATION
        $promotions = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $promotions->items(),
            'links'  => [
                'first' => $promotions->url(1),
                'last'  => $promotions->url($promotions->lastPage()),
                'prev'  => $promotions->previousPageUrl(),
                'next'  => $promotions->nextPageUrl(),
            ],
            'meta'   => [
                'current_page' => $promotions->currentPage(),
                'from'         => $promotions->firstItem(),
                'last_page'    => $promotions->lastPage(),
                'per_page'     => $promotions->perPage(),
                'to'           => $promotions->lastItem(),
                'total'        => $promotions->total(),
            ],
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Impossible de récupérer les promotions.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}


    public function show(int $id): JsonResponse
    {
        try {
            $promotion = $this->service->getById($id);

            if (!$promotion) {
                return response()->json([
                    'message' => 'Promotion not found'
                ], 404);
            }

            return response()->json($promotion);
        } catch (Exception $e) {
            Log::error('Erreur show', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {

        try {
            // Étape 1 : Validation
            Log::info('Étape 1: Validation');
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'sort_order' => 'nullable|integer',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            // Étape 2 : Appel du service
            $promotion = $this->service->create($validated);

            // Étape 3 : Vérification
            if (!$promotion) {
                Log::error('Le service a retourné NULL');
                throw new Exception("Le service a retourné NULL");
            }

            if (!isset($promotion->id) || !$promotion->id) {
                Log::error('Promotion sans ID', [
                    'promotion' => $promotion,
                    'attributes' => method_exists($promotion, 'getAttributes') ? $promotion->getAttributes() : 'N/A'
                ]);
                throw new Exception("Promotion créée mais sans ID");
            }

            return response()->json($promotion, 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_location' => $e->getFile() . ':' . $e->getLine()
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'sort_order' => 'nullable|integer',
                'start_date' => 'sometimes|required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $promotion = $this->service->update($id, $validated);

            if (!$promotion) {
                return response()->json([
                    'message' => 'Promotion not found'
                ], 404);
            }

            return response()->json($promotion);

        } catch (Exception $e) {
            Log::error('Erreur update', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id);

            if (!$deleted) {
                return response()->json([
                    'message' => 'Promotion not found'
                ], 404);
            }

            return response()->json([
                'message' => 'Promotion deleted successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Erreur destroy', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function restore(int $id): JsonResponse
    {
        try {
            $promotion = $this->service->restore($id);

            if (!$promotion) {
                return response()->json([
                    'message' => 'Promotion not found or not deleted'
                ], 404);
            }

            return response()->json([
                'message' => 'Promotion restored successfully',
                'promotion' => $promotion
            ]);

        } catch (Exception $e) {
            Log::error('Erreur restore', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier si une promotion existe et est actuellement active
     * Retourne true si la table n'est pas vide ET s'il existe au moins un enregistrement
     * où end_date >= aujourd'hui (sans vérifier start_date)
     * Si une promotion est active : bouton visible et accès à /promotions autorisé
     * Si aucune promotion active : bouton caché et accès bloqué
     */
    public function checkActive(): JsonResponse
    {
        try {
            // Utiliser startOfDay() pour comparer uniquement les dates (sans les heures)
            $today = now()->startOfDay();
            
            // Vérifier d'abord si la table n'est pas vide
            $tableIsEmpty = TitlePromotion::count() === 0;
            
            if ($tableIsEmpty) {
                return response()->json([
                    'success' => true,
                    'is_active' => false,
                    'promotion' => null,
                    'message' => 'La table title_promotions est vide'
                ]);
            }
            
            // Chercher une promotion où end_date >= aujourd'hui
            // Condition : end_date >= aujourd'hui (sans vérifier start_date)
            // Comparaison uniquement sur les dates (sans les heures)
            // Ne pas inclure les soft deleted (SoftDeletes est activé sur le modèle)
            $activePromotion = TitlePromotion::whereDate('end_date', '>=', $today)
                ->orderBy('end_date', 'desc')
                ->first();

            $isActive = $activePromotion !== null;

            return response()->json([
                'success' => true,
                'is_active' => $isActive,
                'promotion' => $activePromotion ? [
                    'id' => $activePromotion->id,
                    'title' => $activePromotion->title,
                    'start_date' => $activePromotion->start_date ? $activePromotion->start_date->format('Y-m-d H:i:s') : null,
                    'end_date' => $activePromotion->end_date ? $activePromotion->end_date->format('Y-m-d H:i:s') : null,
                ] : null
            ]);
        } catch (Exception $e) {
            Log::error('Erreur checkActive', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'is_active' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste toutes les title_promotions actives pour le select (sans pagination)
     * Uniquement les promotions où end_date >= aujourd'hui
     * (comparaison uniquement sur les dates, sans tenir compte des heures et sans vérifier start_date)
     */
    public function listForSelect(): JsonResponse
    {
        try {
            // Utiliser startOfDay() pour comparer uniquement les dates (sans les heures)
            $today = now()->startOfDay();
            
            // Récupérer uniquement les promotions actives (non supprimées) pour le select
            // Condition : end_date >= aujourd'hui (sans vérifier start_date)
            // Comparaison uniquement sur les dates (sans les heures)
            $promotions = TitlePromotion::whereDate('end_date', '>=', $today)
                ->orderBy('title', 'asc')
                ->select('id', 'title', 'description', 'start_date', 'end_date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $promotions
            ]);
        } catch (Exception $e) {
            Log::error('Erreur listForSelect', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
