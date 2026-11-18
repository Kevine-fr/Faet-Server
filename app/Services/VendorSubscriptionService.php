<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorSubscription;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class VendorSubscriptionService
{
    /**
     * Liste les abonnements de l'utilisateur avec le vendor chargé.
     *
     * @param User $user
     * @return \Illuminate\Support\Collection
     */
    public function listSubscriptions(User $user)
    {
        return $user->vendorSubscriptions()
            ->with([
                'vendor' => function ($query) {
                    $query->withCount(['products', 'subscriptions']) // subscriptions = followers
                        ->with('user') // Ajouter les infos user du vendor
                        ->addSelect([
                            'last_product_created_at' => \App\Models\Product::select('created_at')
                                ->whereColumn('vendor_id', 'vendors.id')
                                ->latest('created_at')
                                ->limit(1)
                        ]);
                }
            ])
            ->latest('created_at')
            ->get();
    }

    /**
     * S'abonner à un vendor. Si une ligne soft-deleted existe, on la restaure.
     *
     * @param User $user
     * @param int $vendorId
     * @return VendorSubscription
     * @throws DomainException
     */
    public function subscribeToVendor(User $user, int $vendorId): VendorSubscription
    {
        return DB::transaction(function () use ($user, $vendorId) {
            $vendor = Vendor::find($vendorId);
            if (! $vendor) {
                throw new DomainException('Vendeur introuvable.');
            }

            // Empêcher de s'abonner à son propre vendor (si l'utilisateur possède un vendor lié)
            if ($user->vendor && $user->vendor->id === $vendorId) {
                throw new DomainException('Vous ne pouvez pas vous abonner à votre propre boutique.');
            }

            // Si un abonnement existant (même soft-deleted) existe -> restore/update
            $existing = VendorSubscription::withTrashed()
                ->where('user_id', $user->id)
                ->where('vendor_id', $vendorId)
                ->first();

            if ($existing) {
                if (! $existing->trashed()) {
                    throw new DomainException('Vous êtes déjà abonné à ce vendeur.');
                }

                // restaure l'abonnement
                $existing->restore();
                $existing->updated_at = Carbon::now();
                $existing->save();

                return $existing->load([
                    'vendor' => function ($query) {
                        $query->withCount(['products', 'subscriptions'])
                            ->with('user')
                            ->addSelect([
                                'last_product_created_at' => \App\Models\Product::select('created_at')
                                    ->whereColumn('vendor_id', 'vendors.id')
                                    ->latest('created_at')
                                    ->limit(1)
                            ]);
                    }
                ]);
            }

            // Créer un nouvel abonnement
            $sub = VendorSubscription::create([
                'user_id' => $user->id,
                'vendor_id' => $vendorId,
            ]);

            return $sub->load([
                'vendor' => function ($query) {
                    $query->withCount(['products', 'subscriptions'])
                        ->with('user')
                        ->addSelect([
                            'last_product_created_at' => \App\Models\Product::select('created_at')
                                ->whereColumn('vendor_id', 'vendors.id')
                                ->latest('created_at')
                                ->limit(1)
                        ]);
                }
            ]);
        });
    }

    /**
     * Se désabonner (soft delete) d'un vendor.
     *
     * @param User $user
     * @param int $vendorId
     * @return bool
     */
    public function unsubscribeFromVendor(User $user, int $vendorId): bool
    {
        $sub = VendorSubscription::where('user_id', $user->id)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $sub) {
            return false;
        }

        return (bool) $sub->delete(); // soft delete
    }

    /**
     * Retourne true si l'utilisateur est abonné à ce vendor (ignorer les soft-deleted).
     *
     * @param User $user
     * @param int $vendorId
     * @return bool
     */
    public function isSubscribed(User $user, int $vendorId): bool
    {
        return VendorSubscription::where('user_id', $user->id)
            ->where('vendor_id', $vendorId)
            ->exists();
    }
}
