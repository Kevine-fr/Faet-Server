<?php

namespace App\Http\Controllers;

use App\Models\Audience;
use Illuminate\Http\Request;
use App\Services\AudienceService;
use App\Http\Resources\AudienceResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class AudienceController extends Controller
{
    protected $audienceService;

    public function __construct(AudienceService $audienceService)
    {
        $this->audienceService = $audienceService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $audiences = Audience::all();
            return response()->json([
                'message' => 'Audience récupérée avec succès',
                'success' => true,
                'error' => false,
                'data' => $audiences
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la récupération de l\'audience',
                'success' => false,
                'error' => true,
                'data' => null
            ], 500);
        }
    }

   /**
     * Créer une audience
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:audiences,name',
            ]);

            $audience = $this->audienceService->create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Audience créée avec succès',
                'data' => new AudienceResource($audience),
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de créer l’audience',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mettre à jour une audience
     */
    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:audiences,name,' . $id,
            ]);

            $audience = $this->audienceService->update($id, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Audience mise à jour',
                'data' => new AudienceResource($audience),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Audience introuvable'], 404);

        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Impossible de mettre à jour l’audience', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Supprimer une audience
     */
    public function destroy($id)
    {
        try {
            $this->audienceService->delete($id);
            return response()->json([
                'status' => 'success',
                'message' => 'Audience supprimée',
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Audience introuvable'], 404);

        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Impossible de supprimer l’audience', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Récupérer une audience par ID
     */
    public function show($id)
    {
        try {
            $audience = $this->audienceService->findById($id);
            return response()->json([
                'status' => 'success',
                'data' => new AudienceResource($audience),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Audience introuvable'], 404);
        }
    }
}