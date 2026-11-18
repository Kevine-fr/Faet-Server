<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SecurePaymentService;
use App\Services\StripePaymentService;
use App\Models\PaymentSession;
use App\Services\InvoiceService;
use App\Services\OrderEmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class StripePaymentController extends Controller
{
    private $securePaymentService;
    private $stripeService;

    public function __construct(SecurePaymentService $securePaymentService, StripePaymentService $stripeService)
    {
        $this->securePaymentService = $securePaymentService;
        $this->stripeService = $stripeService;
    }

    /**
     * Créer une session Stripe Checkout pour les commandes
     */
    public function createCheckoutSession(Request $request)
    {
        //Rate limiting
        $key = 'payment_attempt:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'success' => false,
                'message' => 'Trop de tentatives. Réessayez dans quelques minutes.',
                'retry_after' => RateLimiter::availableIn($key)
            ], 429);
        }

        RateLimiter::hit($key, 300); // 5 minutes

        return DB::transaction(function () use ($request, $key) {
            try {
                $validator = Validator::make($request->all(), [
                    'orders' => 'required|array|min:1',
                    'orders.*.id' => 'required|integer|exists:orders,id',
                    'orders.*.total_amount' => 'required|numeric|min:0.01',
                    'orders.*.vendor' => 'required|array',
                    'orders.*.vendor.id' => 'required|integer',
                    'orders.*.vendor.name' => 'required|string',
                    'success_url' => 'required|url',
                    'cancel_url' => 'required|url',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Données invalides',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $user = auth()->user();

                // Vérifier les activités suspectes
                $this->securePaymentService->checkSuspiciousActivity($user->id);

                // Valider et sécuriser les données
                $validatedData = $this->securePaymentService->validateAndSecurePayment(
                    $request->input('orders'),
                    $user->id
                );

                // Créer la session de paiement sécurisée
                $paymentSession = $this->securePaymentService->createSecurePaymentSession(
                    $validatedData,
                    'stripe',
                    [
                        'success_url' => $request->input('success_url'),
                        'cancel_url' => $request->input('cancel_url')
                    ]
                );

                Log::info('PAYMENT SESSION', $paymentSession->toArray());

                // Créer la session Stripe
                $stripeSession = $this->stripeService->createCheckoutSession(
                    $validatedData,
                    $paymentSession->id,
                    $request->input('success_url'),
                    $request->input('cancel_url')
                );

                // Mettre à jour la session avec l'ID Stripe
                $paymentSession->update([
                    'external_session_id' => $stripeSession['session_id']
                ]);

                // Reset le compteur de tentatives après succès
                RateLimiter::clear($key);
                $this->securePaymentService->resetSuspiciousActivityCounter($user->id);

                $this->securePaymentService->secureLog('info', 'Session Stripe créée', [
                    'session_id' => $paymentSession->id,
                    'orders_count' => $validatedData['orders_count'],
                    'total_amount' => $validatedData['total_amount']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Session créée avec succès',
                    'data' => [
                        'session_id' => $paymentSession->id,
                        'checkout_url' => $stripeSession['checkout_url'],
                        'amount' => $validatedData['total_amount'],
                        'currency' => 'EUR',
                        'orders_count' => $validatedData['orders_count']
                    ]
                ]);

            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur création session Stripe', [
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id()
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la création du paiement';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('stripe_error_')
                ], 500);
            }
        });
    }

    /**
     * Traiter le succès du paiement Stripe
     */
    public function handleCheckoutSuccess(Request $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                $validator = Validator::make($request->all(), [
                    'session_id' => 'required|string'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session ID manquant',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $sessionId = $request->input('session_id');
                $user = auth()->user();

                // Récupérer la session sécurisée
                $sessionData = $this->securePaymentService->getSecurePaymentSession($sessionId, $user->id, true);
                $paymentSession = PaymentSession::findOrFail($sessionId);

                // Vérifier que la session n'a pas déjà été traitée
                if ($paymentSession->status !== 'pending') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session déjà traitée',
                        'status' => $paymentSession->status
                    ], 409);
                }

                // Traiter le paiement avec Stripe
                $stripeResult = $this->stripeService->handleCheckoutSuccess(
                    $paymentSession->external_session_id,
                    $sessionData
                );

                if (!$stripeResult['success']) {
                    $paymentSession->markAsFailed($stripeResult['error']);
                    throw new Exception($stripeResult['error']);
                }

                // Marquer les commandes comme traitées
                $this->securePaymentService->markOrdersAsProcessed(
                    array_column($sessionData['orders'], 'id'),
                    'stripe'
                );

                // Créer les transactions
                $transactions = $this->securePaymentService->createPaymentTransactions(
                    $sessionData['orders'],
                    $stripeResult['payment_intent_id']
                );

                // Marquer la session comme traitée
                $paymentSession->markAsProcessed($stripeResult['payment_intent_id']);

                // Effectuer les transferts aux vendeurs
                $transferResults = $this->stripeService->transferToVendor(
                    $sessionData['orders'],
                    $stripeResult['payment_intent_id']
                );

                // Générer les factures pour chaque commande
                $invoices = [];
                foreach ($sessionData['orders'] as $order) {
                    $invoiceResult = $this->invoiceService->generateInvoiceAfterPayment(
                        $order['id'],
                        $stripeResult['payment_intent_id']
                    );

                    if ($invoiceResult['success']) {
                        $invoices[] = [
                            'invoice_id' => $invoiceResult['invoice_id'],
                            'invoice_number' => $invoiceResult['invoice_number'],
                            'download_url' => $invoiceResult['download_url'],
                            'cloudinary_url' => $invoiceResult['direct_url'], // CORRIGÉ: URL Cloudinary
                            'file_size' => $invoiceResult['file_size']
                        ];

                        // Optionnel: envoyer la facture par email immédiatement
                        try {
                            $this->invoiceService->emailInvoice($invoiceResult['invoice_id']);
                        } catch (Exception $e) {
                            // Log l'erreur mais ne pas échouer le paiement
                            $this->securePaymentService->secureLog('warning', 'Erreur envoi facture par email', [
                                'invoice_id' => $invoiceResult['invoice_id'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                $this->securePaymentService->secureLog('info', 'Paiement Stripe confirmé', [
                    'session_id' => $sessionId,
                    'payment_intent_id' => $stripeResult['payment_intent_id'],
                    'orders_processed' => count($sessionData['orders']),
                    'invoices_generated' => count($invoices)
                ]);

                // ✅ NOUVEAU: Inclure les détails des commandes pour la navigation frontend
                $ordersDetails = array_map(function($order) {
                    return [
                        'id' => $order['id'],
                        'order_number' => $order['order_number'],
                        'total_amount' => $order['total_amount'],
                        'vendor_name' => $order['vendor']['name']
                    ];
                }, $sessionData['orders']);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement confirmé avec succès',
                    'data' => [
                        'session_id' => $sessionId,
                        'payment_intent_id' => $stripeResult['payment_intent_id'],
                        'amount' => $sessionData['total_amount'],
                        'orders_processed' => count($sessionData['orders']),
                        'orders' => $ordersDetails, // ✅ Détails des commandes
                        'transfer_results' => $transferResults,
                        'invoices' => $invoices,
                        'invoices_count' => count($invoices)
                    ]
                ]);

            } catch (Exception $e) {
                // Marquer la session comme échouée si elle existe
                if (isset($paymentSession)) {
                    $paymentSession->markAsFailed($e->getMessage());
                }

                $this->securePaymentService->secureLog('error', 'Erreur confirmation Stripe', [
                    'error' => $e->getMessage(),
                    'session_id' => $request->input('session_id'),
                    'user_id' => auth()->id()
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la confirmation du paiement';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('stripe_confirm_error_')
                ], 500);
            }
        });
    }

    public function checkPaymentStatus(Request $request)
    {
        try {
            $sessionId = $request->input('session_id');
            $user = auth()->user();

            Log::info('🔍 Vérification statut paiement', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'timestamp' => now()->toISOString()
            ]);

            $paymentSession = PaymentSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->first();


            if (!$paymentSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session introuvable'
                ], 404);
            }

            // ✅ CAS 1 : Paiement déjà complété avec succès
            if ($paymentSession->status === 'completed') {
                Log::info('✅ Paiement déjà complété', [
                    'session_id' => $sessionId,
                    'completed_at' => $paymentSession->updated_at
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement confirmé',
                    'data' => [
                        'session_id' => $sessionId,
                        'status' => 'completed',
                        'amount' => $paymentSession->total_amount,
                        'orders_count' => $paymentSession->orders_count,
                        'payment_intent_id' => $paymentSession->external_payment_id
                    ]
                ]);
            }

            // ❌ CAS 2 : Paiement échoué
            if ($paymentSession->status === 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement échoué',
                    'error' => $paymentSession->failure_reason
                ], 400);
            }

            // ⏳ CAS 3 : En attente (pending ou processed)
            // Le webhook n'est peut-être pas encore arrivé
            
            Log::info('⏳ Statut pending - Attente du webhook', [
                'session_id' => $sessionId,
                'current_status' => $paymentSession->status
            ]);

            // Attendre 3 secondes pour laisser le webhook se traiter
            sleep(3);
            $paymentSession->refresh();

            // Re-vérifier après l'attente
            if ($paymentSession->status === 'completed') {
                Log::info('✅ Webhook traité pendant l\'attente', [
                    'session_id' => $sessionId
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement confirmé',
                    'data' => [
                        'session_id' => $sessionId,
                        'status' => 'completed',
                        'amount' => $paymentSession->total_amount,
                        'orders_count' => $paymentSession->orders_count,
                        'payment_intent_id' => $paymentSession->external_payment_id
                    ]
                ]);
            }

            // 🆘 FALLBACK : Le webhook n'est pas arrivé, traiter manuellement
            Log::warning('⚠️ FALLBACK: Traitement manuel car webhook absent', [
                'session_id' => $sessionId,
                'waited' => '3 seconds'
            ]);

            return $this->processPendingPayment($paymentSession, $user);
        } catch (Exception $e) {
            Log::error('❌ Erreur checkPaymentStatus', [
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur lors de la vérification du paiement'
            ], 500);
        }
    }

    /**
     * ✅ NOUVEAU : Traiter un paiement pending (FALLBACK manuel)
    */
    private function processPendingPayment(PaymentSession $paymentSession, $user)
    {
        return DB::transaction(function () use ($paymentSession, $user) {
            try {
                // Vérifier que la session n'a pas été traitée entre temps
                $paymentSession = PaymentSession::where('id', $paymentSession->id)
                    ->lockForUpdate()
                    ->first();

                if ($paymentSession->status === 'completed') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Paiement déjà confirmé',
                        'data' => [
                            'session_id' => $paymentSession->id,
                            'status' => 'completed'
                        ]
                    ]);
                }

                // Récupérer la session sécurisée
                $sessionData = $this->securePaymentService->getSecurePaymentSession(
                    $paymentSession->id, 
                    $user->id, 
                    false
                );

                // Traiter le paiement avec Stripe
                $stripeResult = $this->stripeService->handleCheckoutSuccess(
                    $paymentSession->external_session_id,
                    $sessionData
                );

                if (!$stripeResult['success']) {
                    $paymentSession->markAsFailed($stripeResult['error']);
                    throw new Exception($stripeResult['error']);
                }

                // Appeler la méthode centralisée de traitement
                return $this->completePaymentProcessing(
                    $paymentSession,
                    $sessionData,
                    $stripeResult,
                    'fallback_manual'
                );

            } catch (Exception $e) {
                if (isset($paymentSession)) {
                    $paymentSession->markAsFailed($e->getMessage());
                }

                throw $e;
            }
        });
    }

    /**
     * ✅ NOUVEAU : Logique centralisée de traitement du paiement
     * Utilisée par le webhook ET le fallback manuel
     */
    private function completePaymentProcessing(
        PaymentSession $paymentSession,
        array $sessionData,
        array $stripeResult,
        string $source = 'webhook'
    ) {
        try {
            // Marquer les commandes comme traitées
            $this->securePaymentService->markOrdersAsProcessed(
                array_column($sessionData['orders'], 'id'),
                'stripe'
            );

            // Créer les transactions
            $transactions = $this->securePaymentService->createPaymentTransactions(
                $sessionData['orders'],
                $stripeResult['payment_intent_id']
            );

            // Effectuer les transferts aux vendeurs
            $transferResults = [];
            try {
                $transferResults = $this->stripeService->transferToVendor(
                    $sessionData['orders'],
                    $stripeResult['payment_intent_id']
                );
            } catch (Exception $e) {
                Log::error('Erreur transferts vendeurs', [
                    'source' => $source,
                    'session_id' => $paymentSession->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Générer les factures
            $invoices = [];
            foreach ($sessionData['orders'] as $order) {
                Log::info('Commande payée - Facture disponible à la demande', [
                    'order_id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'total_amount' => $order['total_amount'],
                    'vendor_id' => $order['vendor']['id']
                ]);
            }

            try {
                app(OrderEmailNotificationService::class)
                    ->sendClientOrderConfirmation($sessionData['orders']);

                app(OrderEmailNotificationService::class)
                    ->sendVendorOrderNotifications($sessionData['orders']);

                Log::info('Emails de notification envoyés', [
                    'session_id' => $paymentSession->id,
                    'orders_count' => count($sessionData['orders'])
                ]);
            } catch (Exception $e) {
                Log::warning('Erreur envoi emails notification', [
                    'session_id' => $paymentSession->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Marquer la session comme complète EN DERNIER
            $paymentSession->markAsProcessed($stripeResult['payment_intent_id']);

            Log::info('✅ Paiement traité avec succès', [
                'source' => $source,
                'session_id' => $paymentSession->id,
                'payment_intent_id' => $stripeResult['payment_intent_id'],
                'orders_processed' => count($sessionData['orders']),
                'invoices_generated' => count($invoices),
                'transfers_success' => count(array_filter($transferResults, fn($r) => $r['success'] ?? false))
            ]);

            // ✅ NOUVEAU: Inclure les détails des commandes
            $ordersDetails = array_map(function($order) {
                return [
                    'id' => $order['id'],
                    'order_number' => $order['order_number'],
                    'total_amount' => $order['total_amount'],
                    'vendor_name' => $order['vendor']['name']
                ];
            }, $sessionData['orders']);

            return response()->json([
                'success' => true,
                'message' => 'Paiement confirmé avec succès',
                'data' => [
                    'session_id' => $paymentSession->id,
                    'payment_intent_id' => $stripeResult['payment_intent_id'],
                    'amount' => $sessionData['total_amount'],
                    'orders_processed' => count($sessionData['orders']),
                    'orders' => $ordersDetails, // ✅ Détails des commandes
                    'transfer_results' => $transferResults,
                    'processed_via' => $source
                ]
            ]);

        } catch (Exception $e) {
            Log::error('❌ Erreur completePaymentProcessing', [
                'source' => $source,
                'session_id' => $paymentSession->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Webhook Stripe pour les événements
     */
    public function handleWebhook(Request $request)
    {
        try {
            $payload = $request->getContent();
            $signature = $request->header('Stripe-Signature');

            // Vérifier la signature du webhook
            if (!$this->stripeService->verifyWebhookSignature($payload, $signature)) {
                $this->securePaymentService->secureLog('warning', 'Signature webhook Stripe invalide', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                return response('Unauthorized', 401);
            }

            $event = json_decode($payload, true);

            if (!$event || !isset($event['type'])) {
                return response('Invalid payload', 400);
            }

            Log::info('📨 Webhook reçu', [
                'type' => $event['type'],
                'id' => $event['id'] ?? 'unknown'
            ]);

            // Traiter les événements
            switch ($event['type']) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($event['data']['object']);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($event['data']['object']);
                    break;

                case 'transfer.created':
                    $this->handleTransferCreated($event['data']['object']);
                    break;

                default:
                    $this->securePaymentService->secureLog('info', 'Événement webhook Stripe non géré', [
                        'event_type' => $event['type']
                    ]);
                    break;
            }

            return response('OK', 200);

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur webhook Stripe', [
                'error' => $e->getMessage()
            ]);

            return response('Error', 500);
        }
    }

    /**
     * Gérer la complétion d'une session Checkout via webhook
     */
    private function handleCheckoutSessionCompleted($session)
    {
        return DB::transaction(function () use ($session) {
            try {
                Log::info('🎯 Traitement webhook checkout.session.completed', [
                    'stripe_session_id' => $session['id'],
                    'payment_status' => $session['payment_status']
                ]);

                $paymentSession = PaymentSession::where('external_session_id', $session['id'])
                    ->lockForUpdate()
                    ->first();

                if (!$paymentSession) {
                    Log::warning('⚠️ Session introuvable pour webhook', [
                        'stripe_session_id' => $session['id']
                    ]);
                    return;
                }

                // Vérifier si déjà traité
                if ($paymentSession->status === 'completed') {
                    Log::info('ℹ️ Session déjà traitée', [
                        'session_id' => $paymentSession->id
                    ]);
                    return;
                }

                // Vérifier le statut du paiement Stripe
                if ($session['payment_status'] !== 'paid') {
                    Log::warning('⚠️ Paiement non confirmé dans webhook', [
                        'session_id' => $paymentSession->id,
                        'payment_status' => $session['payment_status']
                    ]);
                    return;
                }

                // Récupérer les données de session
                $sessionData = decrypt($paymentSession->encrypted_data);

                // Préparer le résultat Stripe
                $stripeResult = [
                    'success' => true,
                    'payment_intent_id' => $session['payment_intent']
                ];

                // Appeler la méthode centralisée
                $this->completePaymentProcessing(
                    $paymentSession,
                    $sessionData,
                    $stripeResult,
                    'webhook'
                );

                Log::info('✅ Webhook traité avec succès', [
                    'session_id' => $paymentSession->id,
                    'payment_intent' => $session['payment_intent']
                ]);

            } catch (Exception $e) {
                Log::error('❌ Erreur traitement webhook session complétée', [
                    'stripe_session_id' => $session['id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                if (isset($paymentSession)) {
                    $paymentSession->markAsFailed($e->getMessage());
                }
            }
        });
    }

    /**
     * Gérer l'échec du paiement via webhook
     */
    private function handlePaymentFailed($paymentIntent)
    {
        try {
            $paymentSession = PaymentSession::where('external_payment_id', $paymentIntent['id'])->first();

            if ($paymentSession) {
                $errorMessage = $paymentIntent['last_payment_error']['message'] ?? 'Paiement échoué';
                $paymentSession->markAsFailed($errorMessage);

                $this->securePaymentService->secureLog('warning', 'Paiement Stripe échoué via webhook', [
                    'session_id' => $paymentSession->id,
                    'payment_intent_id' => $paymentIntent['id'],
                    'error' => $errorMessage
                ]);
            }
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur traitement webhook paiement échoué', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntent['id']
            ]);
        }
    }

    /**
     * Gérer la création d'un transfert via webhook
     */
    private function handleTransferCreated($transfer)
    {
        $this->securePaymentService->secureLog('info', 'Transfert Stripe créé via webhook', [
            'transfer_id' => $transfer['id'],
            'amount' => $transfer['amount'] / 100,
            'destination' => substr($transfer['destination'], 0, 10) . '...'
        ]);
    }

    /**
     * Rembourser un paiement
     */
    public function refundPayment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_intent_id' => 'required|string',
                'amount' => 'sometimes|numeric|min:0.01'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->stripeService->processRefund(
                $request->input('payment_intent_id'),
                $request->input('amount')
            );

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Remboursement effectué' : 'Erreur lors du remboursement',
                'data' => $result
            ], $result['success'] ? 200 : 500);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du remboursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
