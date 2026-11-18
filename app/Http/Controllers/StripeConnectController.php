<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class StripeConnectController extends Controller
{
    private $stripeConnectService;

    public function __construct(StripeConnectService $stripeConnectService)
    {
        $this->stripeConnectService = $stripeConnectService;
    }

    /**
     * Créer un compte Stripe Connect Express pour le vendeur connecté
     */
    public function createAccount(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            // Mettre à jour l'email du vendeur si fourni
            if ($request->has('email') && $request->email) {
                $vendor->user->update(['email' => $request->email]);
            }

            $result = $this->stripeConnectService->createExpressAccount(
                $vendor,
                $request->input('return_url'),
                $request->input('refresh_url')
            );

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Compte Stripe Express créé avec succès',
                    'data' => [
                        'account_id' => $result['account_id'],
                        'vendor_id' => $result['vendor_id'],
                        'onboarding_url' => $result['onboarding_url'] ?? null
                    ]
                ], 201);
            }

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Compte Stripe Express créé avec succès' : 'Erreur lors de la création du compte',
                'data' => $result
            ], $result['success'] ? 201 : 500);

        } catch (Exception $e) {
            Log::error('Erreur création compte Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du compte Stripe Express',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Créer un lien d'onboarding Stripe Connect Express
     */
    public function createOnboardingLink(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'return_url' => 'required|url',
                'refresh_url' => 'required|url'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            if (!$vendor->vendor_stripe_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte Stripe Express associé. Créez d\'abord un compte.'
                ], 400);
            }

            $result = $this->stripeConnectService->createOnboardingLink(
                $vendor,
                $request->input('return_url'),
                $request->input('refresh_url')
            );

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Lien d\'onboarding créé' : 'Erreur lors de la création du lien',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur création lien onboarding Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du lien d\'onboarding',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Créer un lien de connexion pour un compte Stripe Express existant
     */
    public function createLoginLink(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            if (!$vendor->vendor_stripe_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte Stripe Express associé'
                ], 400);
            }

            $result = $this->stripeConnectService->createLoginLink($vendor);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Lien de connexion créé' : 'Erreur lors de la création du lien',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur création lien de connexion Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du lien de connexion',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Récupérer le statut du compte Stripe Connect Express
     */
    public function getAccountStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $result = $this->stripeConnectService->getAccountStatus($vendor);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Statut récupéré avec succès' : 'Erreur lors de la récupération du statut',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur récupération statut compte Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Mettre à jour le statut du compte Stripe Connect Express
     */
    public function updateStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            $result = $this->stripeConnectService->updateVendorStatus($vendor);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Statut mis à jour avec succès' : 'Erreur lors de la mise à jour',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur mise à jour statut Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Supprimer le compte Stripe Connect Express
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            if (!$vendor->vendor_stripe_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun compte Stripe Express associé'
                ], 400);
            }

            $result = $this->stripeConnectService->deleteAccount($vendor);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Compte Stripe Express supprimé avec succès' : 'Erreur lors de la suppression',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur suppression compte Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du compte',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Callback après onboarding Stripe Connect Express
     */
    public function handleOnboardingCallback(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $vendor = Vendor::where('user_id', $user->id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur non trouvé'
                ], 404);
            }

            // Mettre à jour le statut du vendeur
            $result = $this->stripeConnectService->updateVendorStatus($vendor);

            if ($result['success']) {
                Log::info('Onboarding Stripe Connect Express complété', [
                    'vendor_id' => $vendor->id,
                    'status' => $result['status']['overall_status'] ?? 'unknown'
                ]);
            }

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Onboarding traité avec succès' : 'Erreur lors du traitement',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur callback onboarding Stripe Connect Express', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du callback',
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur interne'
            ], 500);
        }
    }

    /**
     * Gérer les webhooks Stripe Connect Express
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $signature = $request->header('Stripe-Signature');

            // Vérifier la signature du webhook
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Signature webhook Stripe Connect Express invalide', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $event = json_decode($payload, true);

            if (!$event || !isset($event['type'])) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }

            // Traiter l'événement
            $result = $this->stripeConnectService->handleWebhook($event['type'], $event['data']['object']);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Webhook traité'
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            Log::error('Erreur webhook Stripe Connect Express', [
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Vérifier la signature du webhook Stripe
     */
    private function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            $endpointSecret = config('services.stripe.webhook_secret');
            
            if (!$endpointSecret) {
                Log::warning('Secret webhook Stripe non configuré');
                return false;
            }

            \Stripe\Webhook::constructEvent($payload, $signature, $endpointSecret);
            return true;

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Signature webhook Stripe invalide', [
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (Exception $e) {
            Log::error('Erreur vérification webhook Stripe', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
