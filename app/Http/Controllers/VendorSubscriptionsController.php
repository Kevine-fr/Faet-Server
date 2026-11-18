<?php

namespace App\Http\Controllers;

use App\Services\VendorSubscriptionService;
use DomainException;
use Exception;
use Illuminate\Http\Request;

class VendorSubscriptionsController extends Controller
{
    protected VendorSubscriptionService $service;

    public function __construct(VendorSubscriptionService $service)
    {
        $this->service = $service;
    }
    
    /**
     * GET /subscriptions
     * Retourne les abonnements de l'utilisateur (avec les vendors)
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $subs = $this->service->listSubscriptions($user);

            return response()->json([
                'success' => true,
                'data' => $subs,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les abonnements.',
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
     * POST /vendors/{vendor}/subscribe
     * body: none
     */
    public function store(Request $request, $vendorId)
    {
        try {
            $user = auth()->user();
            $subscription = $this->service->subscribeToVendor($user, (int) $vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Abonnement réussi.',
                'data' => $subscription,
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'abonnement.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        //
    }

    /**
     * DELETE /vendors/{vendor}/unsubscribe
     */
    public function destroy(Request $request, $vendorId)
    {
        try {
            $user = auth()->user();
            $removed = $this->service->unsubscribeFromVendor($user, (int) $vendorId);

            if ($removed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Désabonnement effectué.',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Abonnement introuvable.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'abonnement.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
