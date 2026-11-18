<?php

namespace App\Http\Controllers;

use App\Services\PaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PaymentMethodController extends Controller
{
    protected $paymentMethodService;

    public function __construct(PaymentMethodService $paymentMethodService)
    {
        $this->paymentMethodService = $paymentMethodService;
    }

    /**
     * Récupérer toutes les méthodes de paiement du vendeur
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
            
            $paymentMethods = $this->paymentMethodService->getVendorPaymentMethods($vendor->id);

            return response()->json([
                'success' => true,
                'data' => $paymentMethods
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer une méthode de paiement spécifique
     */
    public function show(Request $request, $id): JsonResponse
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
            
            $paymentMethod = $this->paymentMethodService->getPaymentMethod($id, $vendor->id);
            
            // Déchiffrer la configuration pour l'affichage
            $config = $this->paymentMethodService->decryptPaymentConfig($paymentMethod);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'payment_method' => $paymentMethod,
                    'config' => $config
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Créer une nouvelle méthode de paiement
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'type' => 'required|in:card,paypal,stripe',
                'name' => 'required|string|max:255',
                'config' => 'required|array',
                'is_active' => 'boolean'
            ]);

            $user = $request->user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }
            
            $paymentMethod = $this->paymentMethodService->createPaymentMethod($vendor->id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Méthode de paiement créée avec succès',
                'data' => $paymentMethod
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour une méthode de paiement
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'config' => 'sometimes|array',
                'is_active' => 'sometimes|boolean'
            ]);

            $user = $request->user();
            $vendor = $user->vendor;
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }
            
            $paymentMethod = $this->paymentMethodService->updatePaymentMethod($id, $vendor->id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Méthode de paiement mise à jour avec succès',
                'data' => $paymentMethod
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une méthode de paiement
     */
    public function destroy(Request $request, $id): JsonResponse
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
            
            $this->paymentMethodService->deletePaymentMethod($id, $vendor->id);

            return response()->json([
                'success' => true,
                'message' => 'Méthode de paiement supprimée avec succès'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Définir une méthode comme défaut
     */
    public function setDefault(Request $request, $id): JsonResponse
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
            
            $paymentMethod = $this->paymentMethodService->setDefaultPaymentMethod($id, $vendor->id);

            return response()->json([
                'success' => true,
                'message' => 'Méthode de paiement définie par défaut',
                'data' => $paymentMethod
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les types de paiement disponibles
     */
    public function getAvailableTypes(): JsonResponse
    {
        try {
            $types = [
                [
                    'value' => 'card',
                    'label' => 'Carte bancaire',
                    'description' => 'Visa, Mastercard, American Express'
                ],
                [
                    'value' => 'paypal',
                    'label' => 'PayPal',
                    'description' => 'Paiement via PayPal'
                ],
                [
                    'value' => 'stripe',
                    'label' => 'Stripe',
                    'description' => 'Paiement via Stripe'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $types
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
