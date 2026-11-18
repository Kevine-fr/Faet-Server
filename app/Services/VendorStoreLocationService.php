<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VendorStoreLocationService
{
    /**
     * Récupère la configuration de localisation de boutique pour un utilisateur vendeur.
     */
    public function getStoreLocationForUser(int $userId): array
    {
        $vendor = $this->findVendorByUserId($userId);

        return [
            'store_location' => $vendor->store_location,
            'requires_selection' => is_null($vendor->store_location),
        ];
    }

    /**
     * Met à jour la localisation de boutique pour un utilisateur vendeur.
     */
    public function updateStoreLocationForUser(int $userId, string $storeLocation): Vendor
    {
        $validatedStoreLocation = $this->validateStoreLocation($storeLocation);

        $vendor = $this->findVendorByUserId($userId);

        $vendor->store_location = $validatedStoreLocation;
        $vendor->save();

        Log::info('Store location mise à jour pour le vendeur', [
            'vendor_id' => $vendor->id,
            'user_id' => $userId,
            'store_location' => $validatedStoreLocation,
        ]);

        return $vendor;
    }

    /**
     * Valide que la valeur fournie correspond à une option autorisée.
     */
    private function validateStoreLocation(string $storeLocation): string
    {
        $normalized = strtolower(trim($storeLocation));

        if (!in_array($normalized, ['internal', 'external'], true)) {
            throw new InvalidArgumentException('Option de boutique invalide.');
        }

        return $normalized;
    }

    /**
     * Récupère le vendeur lié à l'utilisateur ou lève une exception.
     */
    private function findVendorByUserId(int $userId): Vendor
    {
        $vendor = Vendor::where('user_id', $userId)->first();

        if (!$vendor) {
            throw new ModelNotFoundException('Profil vendeur introuvable pour cet utilisateur.');
        }

        return $vendor;
    }
}


