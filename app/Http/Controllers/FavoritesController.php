<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\favorites;
use Illuminate\Http\Request;
use App\Services\FavoriteService;
use Exception;
use Illuminate\Validation\ValidationException;

class FavoritesController extends Controller
{
    protected FavoriteService $favoriteService;

    public function __construct(FavoriteService $favoriteService)
    {
        $this->favoriteService = $favoriteService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $user = auth()->user();
            $favorites = $this->favoriteService->listFavorites($user);

            return response()->json([
                'success' => true,
                'data' => $favorites,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les favoris.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
        try {
            $data = $request->validate([
                'product_id' => 'required|integer|exists:products,id',
            ]);

            $user = auth()->user();

            $favorite = $this->favoriteService->addToFavorites($user, (int) $data['product_id']);

            return response()->json([
                'success' => true,
                'message' => 'Produit ajouté aux favoris.',
                'data' => $favorite,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\DomainException $e) {
            // erreurs métiers (ex: favori déjà existant, favori sur son propre produit, etc.)
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de l\'ajout en favori.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Favorite $favorite)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Favorite $favorite)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Favorite $favorite)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($productId)
    {
        try {
            $user = auth()->user();

            $removed = $this->favoriteService->removeFromFavorites($user, (int) $productId);

            if ($removed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Favori supprimé.',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Favori introuvable.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la suppression du favori.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
