<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentSession;
use App\Services\InvoiceService;
use App\Services\SecurePaymentService;
use App\Services\PayPalPaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class PayPalPaymentController extends Controller
{
    private $securePaymentService;
    protected $paypalService;
    private $invoiceService;

    public function __construct(SecurePaymentService $securePaymentService, PayPalPaymentService $paypalService, InvoiceService $invoiceService)
    {
        $this->securePaymentService = $securePaymentService;
        $this->paypalService = $paypalService;
        $this->invoiceService = $invoiceService;
    }

    /**
     * Créer un ordre PayPal pour les commandes
     */
    public function createPayPalOrder(Request $request)
    {
        // Rate limiting
        $key = 'payment_attempt:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'success' => false,
                'message' => 'Trop de tentatives. Réessayez dans quelques minutes.',
                'retry_after' => RateLimiter::availableIn($key)
            ], 429);
        }

        RateLimiter::hit($key, 300);

        return DB::transaction(function () use ($request, $key) {
            try {
                $validator = Validator::make($request->all(), [
                    'orders' => 'required|array|min:1',
                    'orders.*.id' => 'required|integer|exists:orders,id',
                    'orders.*.total_amount' => 'required|numeric|min:0.01',
                    'orders.*.vendor' => 'required|array',
                    'orders.*.vendor.id' => 'required|integer',
                    'orders.*.vendor.name' => 'required|string',
                    'success_url' => 'required|url|max:2048',
                    'cancel_url' => 'required|url|max:2048',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Données invalides',
                        'errors' => $validator->errors()
                    ], 422);
                }

                // Vérifier que l'utilisateur possède ces commandes
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
                    'paypal',
                    [
                        'success_url' => $request->input('success_url'),
                        'cancel_url' => $request->input('cancel_url')
                    ]
                );

                // Créer l'ordre PayPal
                $paypalOrder = $this->paypalService->createPayPalOrder(
                    $validatedData,
                    $paymentSession->id,
                    $request->input('success_url'),
                    $request->input('cancel_url')
                );

                // Mettre à jour la session avec l'ID PayPal
                $paymentSession->update([
                    'external_session_id' => $paypalOrder['paypal_order_id']
                ]);

                // Reset le compteur de tentatives
                RateLimiter::clear($key);
                $this->securePaymentService->resetSuspiciousActivityCounter($user->id);

                $this->securePaymentService->secureLog('info', 'Ordre PayPal créé', [
                    'session_id' => $paymentSession->id,
                    'paypal_order_id' => $paypalOrder['paypal_order_id'],
                    'orders_count' => $validatedData['orders_count'],
                    'total_amount' => $validatedData['total_amount']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Ordre PayPal créé avec succès',
                    'data' => [
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrder['paypal_order_id'],
                        'approval_url' => $paypalOrder['approval_url'],
                        'amount' => $validatedData['total_amount'],
                        'currency' => 'EUR',
                        'orders_count' => $validatedData['orders_count']
                    ]
                ]);

            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur création ordre PayPal', [
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id()
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la création du paiement PayPal';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('paypal_error_')
                ], 500);
            }
        });
    }

    /**
     * Capturer le paiement PayPal après approbation
     */
    public function capturePayPalPayment(Request $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                $validator = Validator::make($request->all(), [
                    'paypal_order_id' => 'required|string',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Données invalides',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $paypalOrderId = $request->input('paypal_order_id');
                $user = auth()->user();

                // Récupérer la session sécurisée
                $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->first();

                if (!$paymentSession) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session PayPal introuvable ou déjà traitée'
                    ], 404);
                }

                $sessionData = $this->securePaymentService->getSecurePaymentSession(
                    $paymentSession->id,
                    $user->id
                );

                // Traiter la capture PayPal
                $paypalResult = $this->paypalService->processPayPalCapture(
                    $paypalOrderId,
                    $sessionData
                );

                if (!$paypalResult['success']) {
                    $paymentSession->markAsFailed($paypalResult['error']);
                    throw new Exception($paypalResult['error']);
                }

                // Marquer les commandes comme traitées
                $this->securePaymentService->markOrdersAsProcessed(
                    array_column($sessionData['orders'], 'id'),
                    'paypal'
                );

                // Créer les transactions
                $transactions = $this->securePaymentService->createPaymentTransactions(
                    $sessionData['orders'],
                    $paypalResult['capture_id']
                );

                // Marquer la session comme traitée
                $paymentSession->markAsProcessed($paypalResult['capture_id']);

                // Effectuer les transferts aux vendeurs
                $transferResults = $this->paypalService->processVendorPayouts(
                    $sessionData['orders'],
                    $paypalResult['capture_id']
                );

                $this->securePaymentService->secureLog('info', 'Paiement PayPal confirmé', [
                    'session_id' => $paymentSession->id,
                    'paypal_order_id' => $paypalOrderId,
                    'capture_id' => $paypalResult['capture_id'],
                    'orders_processed' => count($sessionData['orders'])
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement PayPal confirmé avec succès',
                    'data' => [
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $paypalResult['capture_id'],
                        'amount' => $sessionData['total_amount'],
                        'orders_processed' => count($sessionData['orders']),
                        'transfer_results' => $transferResults
                    ]
                ]);

            } catch (Exception $e) {
                if (isset($paymentSession)) {
                    $paymentSession->markAsFailed($e->getMessage());
                }

                $this->securePaymentService->secureLog('error', 'Erreur capture PayPal', [
                    'error' => $e->getMessage(),
                    'paypal_order_id' => $request->input('paypal_order_id'),
                    'user_id' => auth()->id()
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la confirmation du paiement PayPal';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('paypal_capture_error_')
                ], 500);
            }
        });
    }

    /**
     * Gérer l'approbation PayPal (callback de succès)
     */
    public function handlePayPalApproval(Request $request)
    {
        try {
            $paypalOrderId = $request->query('token'); // PayPal renvoie l'order ID dans le paramètre 'token'
            $payerId = $request->query('PayerID');

            $this->securePaymentService->secureLog('info', 'Callback approbation PayPal reçu', [
                'paypal_order_id' => $paypalOrderId,
                'payer_id' => $payerId
            ]);

            if (!$paypalOrderId) {
                throw new Exception('ID de commande PayPal manquant');
            }

            // Récupérer la session
            $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)
                ->where('status', 'pending')
                ->first();

            if (!$paymentSession) {
                throw new Exception('Session PayPal introuvable');
            }

            $sessionData = decrypt($paymentSession->encrypted_data);
            $originalSuccessUrl = $sessionData['urls']['success_url'];

            // Auto-capture du paiement
            try {
                $captureResult = $this->paypalService->processPayPalCapture($paypalOrderId, $sessionData);

                if ($captureResult['success']) {
                    // Marquer les commandes comme traitées
                    $this->securePaymentService->markOrdersAsProcessed(
                        array_column($sessionData['orders'], 'id'),
                        'paypal'
                    );

                    // Créer les transactions
                    $this->securePaymentService->createPaymentTransactions(
                        $sessionData['orders'],
                        $captureResult['capture_id']
                    );

                    // Marquer la session comme traitée
                    $paymentSession->markAsProcessed($captureResult['capture_id']);

                    // Effectuer les transferts aux vendeurs avec logging approprié
                    $transferResults = [];
                    try {
                        $transferResults = $this->paypalService->processVendorPayouts(
                            $sessionData['orders'],
                            $captureResult['capture_id']
                        );

                        $this->securePaymentService->secureLog('info', 'Transferts vendeurs PayPal complétés', [
                            'paypal_order_id' => $paypalOrderId,
                            'capture_id' => $captureResult['capture_id'],
                            'total_transfers' => count($transferResults),
                            'successful_transfers' => count(array_filter($transferResults, fn($r) => $r['success'])),
                            'failed_transfers' => count(array_filter($transferResults, fn($r) => !$r['success']))
                        ]);
                    } catch (Exception $transferException) {
                        // Ne pas échouer tout le processus si les transferts échouent
                        $this->securePaymentService->secureLog('error', 'Erreur transferts vendeurs PayPal', [
                            'paypal_order_id' => $paypalOrderId,
                            'capture_id' => $captureResult['capture_id'],
                            'error' => $transferException->getMessage()
                        ]);
                        // Les transferts pourront être retraités manuellement
                    }

                    $invoices = [];
                    foreach ($sessionData['orders'] as $order) {
                        $invoiceResult = $this->invoiceService->generateInvoiceAfterPayment(
                            $order['id'],
                            null, // pas de payment_intent pour PayPal
                            $captureResult['capture_id']
                        );

                        if ($invoiceResult['success']) {
                            $invoices[] = [
                                'invoice_id' => $invoiceResult['invoice_id'],
                                'invoice_number' => $invoiceResult['invoice_number'],
                                'download_url' => $invoiceResult['download_url'],
                                'cloudinary_url' => $invoiceResult['direct_url'], // CORRIGÉ
                                'file_size' => $invoiceResult['file_size']
                            ];

                            // Optionnel: envoyer par email
                            try {
                                $this->invoiceService->emailInvoice($invoiceResult['invoice_id']);
                            } catch (Exception $e) {
                                $this->securePaymentService->secureLog('warning', 'Erreur envoi facture PayPal', [
                                    'invoice_id' => $invoiceResult['invoice_id'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }

                    $this->securePaymentService->secureLog('info', 'Auto-capture PayPal réussie', [
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $captureResult['capture_id'],
                        'invoices_generated' => count($invoices)
                    ]);

                    // Inclure les factures dans l'URL de redirection
                    $invoiceParams = count($invoices) > 0 ? [
                        'has_invoices' => 'true',
                        'invoice_count' => count($invoices),
                        'total_file_size' => array_sum(array_column($invoices, 'file_size'))
                    ] : [];

                    // Redirection vers le frontend avec succès
                    $frontendUrl = $originalSuccessUrl . '?' . http_build_query(array_merge([
                        'payment_method' => 'paypal',
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $captureResult['capture_id'],
                        'status' => 'success',
                        'message' => 'Paiement PayPal confirmé avec succès'
                    ], $invoiceParams));

                    return redirect($frontendUrl);
                } else {
                    throw new Exception('Erreur lors de la capture PayPal');
                }
            } catch (Exception $captureException) {
                $paymentSession->markAsFailed($captureException->getMessage());
                throw $captureException;
            }

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur approbation PayPal', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $request->query('token')
            ]);

            // URL d'erreur par défaut
            $errorUrl = config('app.frontend_url') . '/paiement/success?' . http_build_query([
                'payment_method' => 'paypal',
                'paypal_order_id' => $request->query('token'),
                'status' => 'error',
                'message' => 'Erreur lors du traitement du paiement PayPal'
            ]);

            return redirect($errorUrl);
        }
    }

    /**
     * Gérer l'annulation PayPal
     */
    public function handlePayPalCancel(Request $request)
    {
        $paypalOrderId = $request->query('token');

        $this->securePaymentService->secureLog('info', 'Annulation PayPal', [
            'paypal_order_id' => $paypalOrderId
        ]);

        // Marquer la session comme annulée
        if ($paypalOrderId) {
            $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)->first();
            if ($paymentSession && $paymentSession->status === 'pending') {
                $paymentSession->update(['status' => 'cancelled']);
            }
        }

        // URL d'annulation par défaut
        $cancelUrl = config('app.client_web_url') . '/paiement/cancel?' . http_build_query([
            'payment_method' => 'paypal',
            'paypal_order_id' => $paypalOrderId,
            'reason' => 'user_cancelled'
        ]);
        
        return redirect($cancelUrl);
    }

    /**
     * Créer un ordre PayPal pour une commande individuelle
    */
    public function createSingleOrderPayPal(Request $request)
    {
        // Rate limiting
        $key = 'payment_attempt:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'success' => false,
                'message' => 'Trop de tentatives. Réessayez dans quelques minutes.',
                'retry_after' => RateLimiter::availableIn($key)
            ], 429);
        }

        RateLimiter::hit($key, 300);

        return DB::transaction(function () use ($request, $key) {
            try {
                $validator = Validator::make($request->all(), [
                    'order_id' => 'required|integer|exists:orders,id',
                    'success_url' => 'required|url|max:2048',
                    'cancel_url' => 'required|url|max:2048',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Données invalides',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $user = auth()->user();
                $orderId = $request->input('order_id');

                // Récupérer et vérifier la commande
                $order = Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->where('payment_status', 'pending')
                    ->with(['vendor', 'items.product'])
                    ->first();

                if (!$order) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Commande introuvable ou déjà payée'
                    ], 404);
                }

                // Vérifier les activités suspectes
                $this->securePaymentService->checkSuspiciousActivity($user->id);

                // Transformer la commande au format attendu par validateAndSecurePayment
                $orderData = [[
                    'id' => $order->id,
                    'total_amount' => $order->total_amount,
                    'vendor' => [
                        'id' => $order->vendor->id,
                        'name' => $order->vendor->name
                    ]
                ]];

                // Valider et sécuriser les données
                $validatedData = $this->securePaymentService->validateAndSecurePayment($orderData, $user->id);

                // Créer la session de paiement sécurisée
                $paymentSession = $this->securePaymentService->createSecurePaymentSession(
                    $validatedData,
                    'paypal',
                    [
                        'success_url' => $request->input('success_url'),
                        'cancel_url' => $request->input('cancel_url')
                    ]
                );

                Log::info('cancel_url', array($request->input('cancel_url')));

                // Créer l'ordre PayPal
                $paypalOrder = $this->paypalService->createPayPalOrder(
                    $validatedData,
                    $paymentSession->id,
                    $request->input('success_url'),
                    $request->input('cancel_url')
                );

                // Mettre à jour la session avec l'ID PayPal
                $paymentSession->update([
                    'external_session_id' => $paypalOrder['paypal_order_id']
                ]);

                // Reset le compteur de tentatives
                RateLimiter::clear($key);
                $this->securePaymentService->resetSuspiciousActivityCounter($user->id);

                $this->securePaymentService->secureLog('info', 'Ordre PayPal commande individuelle créé', [
                    'session_id' => $paymentSession->id,
                    'paypal_order_id' => $paypalOrder['paypal_order_id'],
                    'order_id' => $order->id,
                    'total_amount' => $validatedData['total_amount']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Ordre PayPal créé avec succès',
                    'data' => [
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrder['paypal_order_id'],
                        'approval_url' => $paypalOrder['approval_url'],
                        'amount' => $validatedData['total_amount'],
                        'currency' => 'EUR',
                        'order_id' => $order->id,
                        'order_number' => $order->order_number
                    ]
                ]);
            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur création ordre PayPal commande individuelle', [
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                    'order_id' => $request->input('order_id')
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la création du paiement PayPal';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('paypal_single_error_')
                ], 500);
            }
        });
    }

    /**
     * Capturer le paiement PayPal pour commande individuelle
    */
    public function captureSingleOrderPayPal(Request $request)
    {
        return DB::transaction(function () use ($request) {
            try {
                $validator = Validator::make($request->all(), [
                    'paypal_order_id' => 'required|string',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Données invalides',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $paypalOrderId = $request->input('paypal_order_id');
                $user = auth()->user();

                // Récupérer la session sécurisée
                $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->first();

                if (!$paymentSession) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session PayPal introuvable ou déjà traitée'
                    ], 404);
                }

                $sessionData = $this->securePaymentService->getSecurePaymentSession(
                    $paymentSession->id,
                    $user->id
                );

                // Traiter la capture PayPal
                $paypalResult = $this->paypalService->processPayPalCapture(
                    $paypalOrderId,
                    $sessionData
                );

                if (!$paypalResult['success']) {
                    $paymentSession->markAsFailed($paypalResult['error']);
                    throw new Exception($paypalResult['error']);
                }

                // Marquer les commandes comme traitées
                $this->securePaymentService->markOrdersAsProcessed(
                    array_column($sessionData['orders'], 'id'),
                    'paypal'
                );

                // Créer les transactions
                $transactions = $this->securePaymentService->createPaymentTransactions(
                    $sessionData['orders'],
                    $paypalResult['capture_id']
                );

                // Marquer la session comme traitée
                $paymentSession->markAsProcessed($paypalResult['capture_id']);

                // Effectuer les transferts aux vendeurs avec gestion d'erreur robuste
                $transferResults = [];
                try {
                    $transferResults = $this->paypalService->processVendorPayouts(
                        $sessionData['orders'],
                        $paypalResult['capture_id']
                    );

                    $this->securePaymentService->secureLog('info', 'Transferts vendeurs PayPal commande individuelle complétés', [
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $paypalResult['capture_id'],
                        'order_id' => $sessionData['orders'][0]['id'],
                        'successful_transfers' => count(array_filter($transferResults, fn($r) => $r['success'])),
                        'failed_transfers' => count(array_filter($transferResults, fn($r) => !$r['success']))
                    ]);
                } catch (Exception $transferException) {
                    // Ne pas échouer le processus si les transferts échouent
                    $this->securePaymentService->secureLog('error', 'Erreur transferts vendeurs PayPal commande individuelle', [
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $paypalResult['capture_id'],
                        'order_id' => $sessionData['orders'][0]['id'],
                        'error' => $transferException->getMessage()
                    ]);
                }

                $this->securePaymentService->secureLog('info', 'Paiement PayPal commande individuelle confirmé', [
                    'paypal_order_id' => $paypalOrderId,
                    'capture_id' => $paypalResult['capture_id'],
                    'order_id' => $sessionData['orders'][0]['id']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement PayPal confirmé avec succès',
                    'data' => [
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $paypalResult['capture_id'],
                        'amount' => $sessionData['total_amount'],
                        'order_id' => $sessionData['orders'][0]['id'],
                        'order_number' => $sessionData['orders'][0]['order_number'],
                        'transfer_results' => $transferResults
                    ]
                ]);
            } catch (Exception $e) {
                if (isset($paymentSession)) {
                    $paymentSession->markAsFailed($e->getMessage());
                }

                $this->securePaymentService->secureLog('error', 'Erreur capture PayPal commande individuelle', [
                    'error' => $e->getMessage(),
                    'paypal_order_id' => $request->input('paypal_order_id'),
                    'user_id' => auth()->id()
                ]);

                $message = config('app.debug') ? $e->getMessage() : 'Erreur lors de la confirmation du paiement PayPal';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error_id' => uniqid('paypal_single_capture_error_')
                ], 500);
            }
        });
    }

    /**
     * Gérer l'approbation PayPal pour commande individuelle (callback de succès)
    */
    public function handleSingleOrderApproval(Request $request)
    {
        try {
            $paypalOrderId = $request->query('token');
            $payerId = $request->query('PayerID');

            $this->securePaymentService->secureLog('info', 'Callback approbation PayPal commande individuelle reçu', [
                'paypal_order_id' => $paypalOrderId,
                'payer_id' => $payerId
            ]);

            if (!$paypalOrderId) {
                throw new Exception('ID de commande PayPal manquant');
            }

            // Récupérer la session
            $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)
                ->where('status', 'pending')
                ->first();

            if (!$paymentSession) {
                throw new Exception('Session PayPal introuvable');
            }

            $sessionData = decrypt($paymentSession->encrypted_data);
            $originalSuccessUrl = $sessionData['urls']['success_url'];

            // Auto-capture du paiement
            try {
                $captureResult = $this->paypalService->processPayPalCapture($paypalOrderId, $sessionData);

                if ($captureResult['success']) {
                    // Marquer les commandes comme traitées
                    $this->securePaymentService->markOrdersAsProcessed(
                        array_column($sessionData['orders'], 'id'),
                        'paypal'
                    );

                    // Créer les transactions
                    $this->securePaymentService->createPaymentTransactions(
                        $sessionData['orders'],
                        $captureResult['capture_id']
                    );

                    // Marquer la session comme traitée
                    $paymentSession->markAsProcessed($captureResult['capture_id']);

                    // Effectuer les transferts aux vendeurs avec logging approprié
                    $transferResults = [];
                    try {
                        $transferResults = $this->paypalService->processVendorPayouts(
                            $sessionData['orders'],
                            $captureResult['capture_id']
                        );

                        $this->securePaymentService->secureLog('info', 'Transferts vendeurs PayPal commande individuelle complétés', [
                            'paypal_order_id' => $paypalOrderId,
                            'capture_id' => $captureResult['capture_id'],
                            'order_id' => $sessionData['orders'][0]['id'],
                            'successful_transfers' => count(array_filter($transferResults, fn($r) => $r['success'])),
                            'failed_transfers' => count(array_filter($transferResults, fn($r) => !$r['success']))
                        ]);
                    } catch (Exception $transferException) {
                        $this->securePaymentService->secureLog('error', 'Erreur transferts vendeurs PayPal commande individuelle', [
                            'paypal_order_id' => $paypalOrderId,
                            'capture_id' => $captureResult['capture_id'],
                            'order_id' => $sessionData['orders'][0]['id'],
                            'error' => $transferException->getMessage()
                        ]);
                    }

                    $this->securePaymentService->secureLog('info', 'Auto-capture PayPal commande individuelle réussie', [
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $captureResult['capture_id'],
                        'order_id' => $sessionData['orders'][0]['id']
                    ]);

                    // Redirection vers le frontend avec succès
                    $frontendUrl = $originalSuccessUrl . '?' . http_build_query([
                        'payment_method' => 'paypal',
                        'session_id' => $paymentSession->id,
                        'paypal_order_id' => $paypalOrderId,
                        'capture_id' => $captureResult['capture_id'],
                        'status' => 'success',
                        'order_id' => $sessionData['orders'][0]['id'],
                        'message' => 'Paiement PayPal confirmé avec succès'
                    ]);

                    return redirect($frontendUrl);
                } else {
                    throw new Exception('Erreur lors de la capture PayPal');
                }
            } catch (Exception $captureException) {
                $paymentSession->markAsFailed($captureException->getMessage());
                throw $captureException;
            }
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur approbation PayPal commande individuelle', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $request->query('token')
            ]);

            // URL d'erreur par défaut
            $errorUrl = config('app.frontend_url') . '/commande/paiement/error?' . http_build_query([
                'payment_method' => 'paypal',
                'paypal_order_id' => $request->query('token'),
                'status' => 'error',
                'message' => 'Erreur lors du traitement du paiement PayPal'
            ]);

            return redirect($errorUrl);
        }
    }

    /**
     * Gérer l'annulation PayPal pour commande individuelle
     */
    public function handleSingleOrderCancel(Request $request)
    {
        $paypalOrderId = $request->query('token');

        $this->securePaymentService->secureLog('info', 'Annulation PayPal commande individuelle', [
            'paypal_order_id' => $paypalOrderId
        ]);

        // Marquer la session comme annulée
        if ($paypalOrderId) {
            $paymentSession = PaymentSession::where('external_session_id', $paypalOrderId)->first();
            if ($paymentSession && $paymentSession->status === 'pending') {
                $paymentSession->update(['status' => 'cancelled']);
            }
        }

        // URL d'annulation par défaut
        $cancelUrl = config('app.frontend_url') . '/commande/paiement/cancel?' . http_build_query([
            'payment_method' => 'paypal',
            'paypal_order_id' => $paypalOrderId,
            'reason' => 'user_cancelled'
        ]);

        return redirect($cancelUrl);
    }

    /**
     * Webhook PayPal pour les notifications
     */
    public function handlePayPalWebhook(Request $request)
    {
        try {
            $payload = $request->getContent();
            $headers = $request->headers->all();

            // Vérifier la signature PayPal
            if (!$this->paypalService->verifyWebhookSignature($payload, $headers)) {
                $this->securePaymentService->secureLog('warning', 'Signature webhook PayPal invalide', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                return response('Unauthorized', 401);
            }

            $event = json_decode($payload, true);

            if (!$event || !isset($event['event_type'])) {
                return response('Invalid payload', 400);
            }

            // Traiter les événements
            switch ($event['event_type']) {
                case 'CHECKOUT.ORDER.APPROVED':
                    $this->handleOrderApproved($event['resource']);
                    break;

                case 'CHECKOUT.ORDER.COMPLETED':
                    $this->handleOrderCompleted($event['resource']);
                    break;

                case 'PAYMENT.CAPTURE.COMPLETED':
                    $this->handleCaptureCompleted($event['resource']);
                    break;

                case 'PAYMENT.CAPTURE.DENIED':
                    $this->handleCaptureDenied($event['resource']);
                    break;

                default:
                    $this->securePaymentService->secureLog('info', 'Événement webhook PayPal non géré', [
                        'event_type' => $event['event_type']
                    ]);
                    break;
            }

            return response('OK', 200);

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur webhook PayPal', [
                'error' => $e->getMessage()
            ]);

            return response('Error', 500);
        }
    }

    /**
     * Rembourser un paiement PayPal
    */
    public function refundPayPalPayment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'capture_id' => 'required|string',
                'amount' => 'sometimes|numeric|min:0.01',
                'reason' => 'sometimes|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->paypalService->processRefund(
                $request->input('capture_id'),
                $request->input('amount'),
                $request->input('reason', 'Remboursement demandé')
            );

            $this->securePaymentService->secureLog('info', 'Remboursement PayPal traité', [
                'capture_id' => $request->input('capture_id'),
                'amount' => $request->input('amount'),
                'success' => $result['success']
            ]);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Remboursement effectué' : 'Erreur lors du remboursement',
                'data' => $result
            ], $result['success'] ? 200 : 500);
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur remboursement PayPal', [
                'error' => $e->getMessage(),
                'capture_id' => $request->input('capture_id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du remboursement PayPal',
                'error_id' => uniqid('paypal_refund_error_')
            ], 500);
        }
    }

    /**
     * Obtenir le statut d'une session de paiement PayPal
    */
    public function getPayPalSessionStatus(Request $request, string $sessionId)
    {
        try {
            $user = auth()->user();

            $paymentSession = PaymentSession::where('external_session_id', $sessionId)
                ->where('user_id', $user->id)
                ->first();

            if (!$paymentSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session PayPal introuvable'
                ], 404);
            }

            $sessionData = $this->securePaymentService->getSecurePaymentSession(
                $sessionId,
                $user->id
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'status' => $paymentSession->status,
                    'payment_method' => $paymentSession->payment_method,
                    'total_amount' => $paymentSession->total_amount,
                    'orders_count' => $paymentSession->orders_count,
                    'external_session_id' => $paymentSession->external_session_id,
                    'created_at' => $paymentSession->created_at,
                    'expires_at' => $paymentSession->expires_at,
                    'processed_at' => $paymentSession->processed_at,
                    'orders' => array_map(function ($order) {
                        return [
                            'id' => $order['id'],
                            'order_number' => $order['order_number'] ?? null,
                            'total_amount' => $order['total_amount'],
                            'vendor_name' => $order['vendor']['name'] ?? null
                        ];
                    }, $sessionData['orders'])
                ]
            ]);
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur récupération statut PayPal', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut',
                'error_id' => uniqid('paypal_status_error_')
            ], 500);
        }
    }

    /**
     * Annuler une session de paiement PayPal
    */
    public function cancelPayPalSession(Request $request, string $sessionId)
    {
        return DB::transaction(function () use ($sessionId) {
            try {
                $user = auth()->user();
                
                $paymentSession = PaymentSession::where('external_session_id', $sessionId)
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (!$paymentSession) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Session introuvable ou déjà traitée'
                    ], 404);
                }

                // Marquer comme annulée
                $paymentSession->update([
                    'status' => 'cancelled',
                    'processed_at' => now()
                ]);

                $this->securePaymentService->secureLog('info', 'Session PayPal annulée', [
                    'session_id' => $sessionId,
                    'user_id' => $user->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Session annulée avec succès',
                    'data' => [
                        'session_id' => $sessionId,
                        'status' => 'cancelled'
                    ]
                ]);
            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur annulation session PayPal', [
                    'error' => $e->getMessage(),
                    'session_id' => $sessionId
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'annulation',
                    'error_id' => uniqid('paypal_cancel_error_')
                ], 500);
            }
        });
    }

    // Méthodes privées pour les webhooks

    private function handleOrderApproved($order)
    {
        $this->securePaymentService->secureLog('info', 'Ordre PayPal approuvé via webhook', [
            'paypal_order_id' => $order['id']
        ]);
    }

    private function handleOrderCompleted($order)
    {
        try {
            $paymentSession = PaymentSession::where('external_session_id', $order['id'])->first();

            if ($paymentSession && $paymentSession->status === 'pending') {
                $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
                $paymentSession->markAsProcessed($captureId);

                $this->securePaymentService->secureLog('info', 'Ordre PayPal complété via webhook', [
                    'session_id' => $paymentSession->id,
                    'paypal_order_id' => $order['id']
                ]);
            }
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur traitement ordre complété', [
                'error' => $e->getMessage(),
                'paypal_order_id' => $order['id']
            ]);
        }
    }

    private function handleCaptureCompleted($capture)
    {
        $this->securePaymentService->secureLog('info', 'Capture PayPal réussie via webhook', [
            'capture_id' => $capture['id'],
            'amount' => $capture['amount']['value']
        ]);
    }

    private function handleCaptureDenied($capture)
    {
        try {
            // Trouver et marquer les sessions concernées comme échouées
            $paymentSessions = PaymentSession::where('external_payment_id', $capture['id'])
                ->where('status', 'pending')
                ->get();

            foreach ($paymentSessions as $session) {
                $session->markAsFailed('Capture PayPal refusée');
            }

            $this->securePaymentService->secureLog('warning', 'Capture PayPal refusée via webhook', [
                'capture_id' => $capture['id'],
                'sessions_affected' => $paymentSessions->count()
            ]);
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur traitement capture refusée', [
                'error' => $e->getMessage(),
                'capture_id' => $capture['id']
            ]);
        }
    }

    /**
     * Obtenir le statut d'un ordre PayPal
     */
    public function getPayPalOrderStatus(Request $request, string $paypalOrderId)
    {
        try {
            // Récupérer les informations depuis le cache
            $orderInfo = cache()->get("paypal_order_{$paypalOrderId}");

            if (!$orderInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ordre PayPal introuvable'
                ], 404);
            }

            // Récupérer le statut des commandes associées
            $orders = Order::whereIn('id', $orderInfo['order_ids'])
                ->select('id', 'order_number', 'payment_status', 'total_amount')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'paypal_order_id' => $paypalOrderId,
                    'orders' => $orders,
                    'breakdown' => $orderInfo['breakdown'],
                    'created_at' => $orderInfo['created_at']
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
