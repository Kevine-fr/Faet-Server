<?php

namespace App\Http\Controllers;

use App\Services\PolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PolicyController extends Controller
{
    protected $policyService;

    public function __construct(PolicyService $policyService)
    {
        $this->policyService = $policyService;
    }

    /**
     * Récupérer les politiques du vendeur connecté
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }
            
            $policies = $this->policyService->getVendorPolicies($vendor->id);

            return response()->json([
                'success' => true,
                'data' => $policies
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Créer ou mettre à jour les politiques
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'cgv' => 'nullable|string',
                'politique_de_retour' => 'nullable|string|max:255',
                'condition_de_retour' => 'nullable|string|max:255',
                'pays_de_vente' => 'nullable|string|max:255',
            ]);

            $user = $request->user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }
            
            $policies = $this->policyService->createOrUpdatePolicies($vendor->id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Politiques sauvegardées avec succès',
                'data' => $policies
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer les politiques du vendeur
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }
            
            $deleted = $this->policyService->deleteVendorPolicies($vendor->id);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Politiques supprimées avec succès'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Aucune politique trouvée'
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
