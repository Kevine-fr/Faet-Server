<?php

namespace App\Http\Controllers;

use App\Services\VendorStoreLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class VendorStoreLocationController extends Controller
{
    public function __construct(
        private readonly VendorStoreLocationService $vendorStoreLocationService
    ) {
    }

    /**
     * Retourne la configuration de localisation de boutique du vendeur connecté.
     */
    public function show(Request $request)
    {
        $userId = Auth::guard('api')->id();

        $data = $this->vendorStoreLocationService->getStoreLocationForUser($userId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Met à jour la localisation de boutique du vendeur connecté.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'store_location' => [
                'required',
                Rule::in(['internal', 'external']),
            ],
        ]);

        $userId = Auth::guard('api')->id();

        try {
            $vendor = $this->vendorStoreLocationService->updateStoreLocationForUser(
                $userId,
                $validated['store_location']
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Localisation de boutique mise à jour avec succès.',
            'data' => [
                'store_location' => $vendor->store_location,
            ],
        ]);
    }
}


