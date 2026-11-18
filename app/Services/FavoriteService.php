<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use DomainException;

class FavoriteService
{
    /**
     * Retourne la liste des favoris pour un utilisateur (avec le produit chargé).
     *
     * @param User $user
     * @return \Illuminate\Support\Collection
     */

     public function listFavorites(User $user)
    {
        return $user->favorites()
            ->with(['product.vendor', 'product.category', 'product.images', 'product.variants'])
            ->latest('created_at')
            ->get()
            ->map(function (Favorite $fav) {
                return [
                    'id' => $fav->id,
                    'product' => $fav->product,
                    'created_at' => $fav->created_at,
                ];
            });
    }

    /**
     * Ajoute un produit aux favoris de l'utilisateur.
     *
     * @param User $user
     * @param int $productId
     * @return Favorite
     * @throws DomainException si déjà existant ou règles métier violées
     */
    public function addToFavorites(User $user, int $productId): Favorite
    {
        return DB::transaction(function () use ($user, $productId) {
            $product = Product::where('id', $productId)
                ->where('is_active', true)
                ->where('is_blocked', false)
                ->first();

            if(! $product) {
                throw new DomainException('Produit introuvable ou indisponible.');
            }

            // Empêcher de favoriser son propre produit
            if ($user->vendor && $product->vendor_id === $user->vendor->id) {
                throw new DomainException('Vous ne pouvez pas ajouter vos propres produits en favoris.');
            }

            // Chercher la ligne même si soft-deleted et prendre un verrou pour éviter les races
            $existing = Favorite::withTrashed()
                ->where('user_id', $user->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    // Restaurer la ligne soft-deleted (met deleted_at à null)
                    $existing->restore();

                    // mettre à jour updated_at si nécessaire
                    $existing->touch();

                    // charger relations utiles avant retour
                    $existing->load(['product.vendor', 'product.category', 'product.images', 'product.variants']);

                    return $existing;
                }

                // si elle existe et n'est pas supprimée -> doublon réel
                throw new DomainException('Produit déjà présent dans vos favoris.');
            }

            // Création normale
            $fav = Favorite::create([
                'user_id' => $user->id,
                'product_id' => $productId,
            ]);

            //retourner un objet complet
            $fav->load(['product.vendor', 'product.category', 'product.images', 'product.variants']);

            return $fav;
        });
    }

    /**
     * Retire un produit des favoris.
     *
     * @param User $user
     * @param int $productId
     * @return bool true si supprimé, false si non trouvé
     */
    public function removeFromFavorites(User $user, int $productId): bool
    {
        $fav = Favorite::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (! $fav) {
            return false;
        }

        // soft delete si trait SoftDeletes utilisé dans le modèle Favorite
        return (bool) $fav->delete();
    }
}
