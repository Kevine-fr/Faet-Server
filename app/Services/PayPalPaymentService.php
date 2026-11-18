<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalPaymentService
{
    private $baseUrl;
    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $tokenExpiresAt;
    private $securePaymentService;

    public function __construct(SecurePaymentService $securePaymentService)
    {
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->baseUrl = config('services.paypal.mode') === 'live' 
            ? 'https://api.paypal.com' 
            : 'https://api.sandbox.paypal.com';
        $this->securePaymentService = $securePaymentService;
    }

    /**
     * Obtenir un token d'accès PayPal
    */
    public function getAccessToken(): string
    {
        // Vérifier si le token est encore valide (avec marge de 5 minutes)
        if ($this->accessToken && $this->tokenExpiresAt && $this->tokenExpiresAt > now()->addMinutes(5)) {
            return $this->accessToken;
        }

        try {
            $response = Http::timeout(30)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials'
                ]);

            if (!$response->successful()) {
                throw new Exception('Erreur authentification PayPal: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = now()->addSeconds($data['expires_in'] ?? 3600);

            return $this->accessToken;
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur authentification PayPal', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Impossible de s\'authentifier avec PayPal');
        }
    }

    /**
     * Créer un ordre PayPal pour plusieurs commandes avec paiements fractionnés
    */

    public function createPayPalOrder(array $validatedData, string $sessionId, string $successUrl, string $cancelUrl): array
    {
        try {
            $token = $this->getAccessToken();
            $orders = $validatedData['orders'];
            $totalAmount = $validatedData['total_amount'];

            // URLs de callback backend
            $backendBaseUrl = config('app.url');

            // Structure de l'ordre PayPal avec sécurité renforcée
            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => "SESSION_{$sessionId}",
                        'description' => count($orders) . ' commande(s) ' . config('app.name'),
                        'custom_id' => $sessionId, // Pour retrouver notre session
                        'amount' => [
                            'currency_code' => 'EUR',
                            'value' => number_format($totalAmount, 2, '.', ''),
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => 'EUR',
                                    'value' => number_format($totalAmount, 2, '.', '')
                                ]
                            ]
                        ],
                        'items' => $this->buildPayPalItems($orders),
                        'payee' => [
                            'email_address' => config('services.paypal.merchant_email'),
                        ],
                        'payment_instruction' => [
                            'disbursement_mode' => 'DELAYED', // Pour les transferts ultérieurs
                        ]
                    ]
                ],
                'application_context' => [
                    'return_url' => $backendBaseUrl . '/api/paypal/success',
                    'cancel_url' => $backendBaseUrl . '/api/paypal/cancel',
                    'brand_name' => config('app.name'),
                    'landing_page' => 'BILLING',
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'payment_method' => [
                        'payer_selected' => 'PAYPAL',
                        'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED'
                    ]
                ]
            ];

            // Créer l'ordre sur PayPal avec retry et timeout
            $response = Http::timeout(45)
                ->retry(2, 1000)
                ->withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => 'CREATE-ORDER-' . $sessionId, // Idempotency
                    'Prefer' => 'return=representation'
                ])
                ->post("{$this->baseUrl}/v2/checkout/orders", $orderData);

            if (!$response->successful()) {
                $this->securePaymentService->secureLog('error', 'Erreur création ordre PayPal', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'session_id' => $sessionId
                ]);
                throw new Exception('Erreur lors de la création de l\'ordre PayPal: ' . $response->body());
            }

            $paypalOrder = $response->json();

            // Récupérer l'URL d'approbation
            $approvalUrl = collect($paypalOrder['links'])
                ->where('rel', 'approve')
                ->first()['href'] ?? null;

            if (!$approvalUrl) {
                throw new Exception('URL d\'approbation PayPal manquante');
            }

            $this->securePaymentService->secureLog('info', 'Ordre PayPal créé', [
                'session_id' => $sessionId,
                'paypal_order_id' => $paypalOrder['id'],
                'amount' => $totalAmount
            ]);

            return [
                'paypal_order_id' => $paypalOrder['id'],
                'approval_url' => $approvalUrl,
                'amount' => $totalAmount,
                'currency' => 'EUR',
                'orders_count' => count($orders)
            ];

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur création ordre PayPal', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);
            throw $e;
        }
    }

    /**
    * Traiter la capture PayPal
    */
    public function processPayPalCapture(string $paypalOrderId, array $sessionData): array
    {
        return DB::transaction(function () use ($paypalOrderId, $sessionData) {
            try {
                $token = $this->getAccessToken();

                // D'abord récupérer les détails de l'ordre
                $orderResponse = Http::timeout(30)
                    ->withToken($token)
                    ->get("{$this->baseUrl}/v2/checkout/orders/{$paypalOrderId}");

                if (!$orderResponse->successful()) {
                    throw new Exception('Impossible de récupérer les détails de l\'ordre PayPal');
                }

                $orderData = $orderResponse->json();

                if (($orderData['status'] ?? '') !== 'APPROVED') {
                    throw new Exception("Ordre PayPal non approuvé. Statut: " . ($orderData['status'] ?? 'unknown'));
                }

                // Vérifier les montants
                $expectedAmount = number_format($sessionData['total_amount'], 2, '.', '');
                $actualAmount = $orderData['purchase_units'][0]['amount']['value'] ?? '0';

                if ($expectedAmount !== $actualAmount) {
                    throw new Exception("Montant incorrect. Attendu: {$expectedAmount}, Reçu: {$actualAmount}");
                }

                // Capturer le paiement
                $capturePayload = [
                    'note_to_payer' => 'Merci pour votre achat sur ' . config('app.name'),
                    'final_capture' => true
                ];

                $captureResponse = Http::timeout(45)
                    ->retry(2, 1000)
                    ->withToken($token)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'PayPal-Request-Id' => 'CAPTURE-' . $paypalOrderId . '-' . time(),
                        'Prefer' => 'return=representation'
                    ])
                    ->post("{$this->baseUrl}/v2/checkout/orders/{$paypalOrderId}/capture", $capturePayload);

                if (!$captureResponse->successful()) {
                    $errorBody = $captureResponse->body();
                    $this->securePaymentService->secureLog('error', 'Erreur capture PayPal', [
                        'paypal_order_id' => $paypalOrderId,
                        'status' => $captureResponse->status(),
                        'response' => $errorBody
                    ]);

                    // Parser l'erreur PayPal
                    $errorData = json_decode($errorBody, true);
                    $errorMessage = 'Erreur lors de la capture PayPal';
                    if ($errorData && isset($errorData['details'])) {
                        $details = collect($errorData['details'])->pluck('description')->implode(', ');
                        $errorMessage .= ': ' . $details;
                    }

                    throw new Exception($errorMessage);
                }

                $captureData = $captureResponse->json();

                if (($captureData['status'] ?? '') !== 'COMPLETED') {
                    throw new Exception('Capture PayPal non complétée. Statut: ' . ($captureData['status'] ?? 'unknown'));
                }

                $captureId = $captureData['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
                $captureAmount = $captureData['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0;

                if (!$captureId) {
                    throw new Exception('ID de capture PayPal manquant');
                }

                $this->securePaymentService->secureLog('info', 'Capture PayPal réussie', [
                    'paypal_order_id' => $paypalOrderId,
                    'capture_id' => $captureId,
                    'amount' => $captureAmount
                ]);

                return [
                    'success' => true,
                    'capture_id' => $captureId,
                    'amount_captured' => (float) $captureAmount,
                    'currency' => 'EUR',
                    'status' => 'completed'
                ];
            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur traitement capture PayPal', [
                    'paypal_order_id' => $paypalOrderId,
                    'error' => $e->getMessage()
                ]);

                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        });
    }

    /**
     * Effectuer les payouts vers les vendeurs
    */
    public function processVendorPayouts(array $orders, string $captureId): array
    {
        $results = [];
        $breakdown = $this->securePaymentService->calculateTransferBreakdown($orders);

        foreach ($breakdown as $payoutData) {
            $transactionData = [
                'order_id' => $payoutData['order_id'],
                'vendor_id' => $payoutData['vendor_id'],
                'type' => 'transfer',
                'amount' => $payoutData['vendor_amount'],
                'processed_at' => now()
            ];

            try {
                $vendor = Vendor::find($payoutData['vendor_id']);

                if (!$vendor || !$vendor->paypal_email) {
                    $this->securePaymentService->secureLog('warning', 'Email PayPal vendeur manquant', [
                        'vendor_id' => $payoutData['vendor_id'],
                        'order_id' => $payoutData['order_id']
                    ]);

                    // Créer la transaction échouée AVANT de continuer
                    Transaction::create(array_merge($transactionData, [
                        'status' => 'failed',
                        'failure_reason' => 'Email PayPal vendeur non configuré'
                    ]));

                    $results[] = [
                        'order_id' => $payoutData['order_id'],
                        'success' => false,
                        'error' => 'Email PayPal vendeur non configuré'
                    ];
                    continue;
                }

                // Créer le payout PayPal
                $payout = $this->createPayout(
                    $vendor->paypal_email,
                    $payoutData['vendor_amount'],
                    $payoutData['order_id'],
                    $captureId
                );

                if ($payout['success']) {
                    // Créer la transaction de transfert réussie
                    Transaction::create(array_merge($transactionData, [
                        'status' => 'completed',
                        'external_transaction_id' => $payout['payout_item_id']
                    ]));

                    $results[] = [
                        'order_id' => $payoutData['order_id'],
                        'success' => true,
                        'payout_item_id' => $payout['payout_item_id'],
                        'amount_transferred' => $payoutData['vendor_amount'],
                        'platform_fee' => $payoutData['platform_fee']
                    ];
                } else {
                    throw new Exception($payout['error']);
                }
            } catch (Exception $e) {
                // Analyser l'erreur PayPal pour un message plus précis
                $failureReason = $this->parsePayPalPayoutError($e);

                // Créer une transaction échouée avec détails de l'erreur
                Transaction::create(array_merge($transactionData, [
                    'status' => 'failed',
                    'failure_reason' => $failureReason
                ]));

                $results[] = [
                    'order_id' => $payoutData['order_id'],
                    'success' => false,
                    'error' => $e->getMessage()
                ];

                $this->securePaymentService->secureLog('error', 'Erreur payout PayPal', [
                    'order_id' => $payoutData['order_id'],
                    'vendor_id' => $payoutData['vendor_id'],
                    'vendor_email' => $vendor->paypal_email ?? 'non_defini',
                    'error' => $e->getMessage(),
                    'failure_reason' => $failureReason
                ]);
            }
        }

        // Log du résumé des transferts
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $totalCount = count($results);

        $this->securePaymentService->secureLog('info', 'Résumé payouts PayPal', [
            'capture_id' => $captureId,
            'total_transfers' => $totalCount,
            'successful_transfers' => $successCount,
            'failed_transfers' => $totalCount - $successCount
        ]);

        return $results;
    }

    /**
     * Créer un payout PayPal individual
    */
    private function createPayout(string $vendorEmail, float $amount, int $orderId, string $captureId): array
    {
        try {
            $token = $this->getAccessToken();

            $payoutData = [
                'sender_batch_header' => [
                    'sender_batch_id' => 'PAYOUT_' . $orderId . '_' . time(),
                    'email_subject' => 'Paiement pour votre vente sur ' . config('app.name'),
                    'email_message' => 'Vous avez reçu un paiement pour la commande #' . $orderId,
                    'note' => 'Paiement vendeur - Commande #' . $orderId
                ],
                'items' => [
                    [
                        'recipient_type' => 'EMAIL',
                        'amount' => [
                            'value' => number_format($amount, 2, '.', ''),
                            'currency' => 'EUR'
                        ],
                        'receiver' => $vendorEmail,
                        'note' => "Paiement commande #{$orderId}",
                        'sender_item_id' => "ORDER_{$orderId}_{$captureId}",
                        'recipient_wallet' => 'PAYPAL'
                    ]
                ]
            ];

            // Log de la tentative de payout
            $this->securePaymentService->secureLog('info', 'Tentative payout PayPal', [
                'order_id' => $orderId,
                'vendor_email' => $vendorEmail,
                'amount' => $amount
            ]);

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => 'PAYOUT-' . $orderId . '-' . time()
                ])
                ->post("{$this->baseUrl}/v1/payments/payouts", $payoutData);

            if (!$response->successful()) {
                $errorBody = $response->body();
                $errorData = json_decode($errorBody, true);

                // Parser l'erreur PayPal pour un message plus explicite
                $errorMessage = 'Erreur API PayPal Payout';
                if ($errorData && isset($errorData['details'])) {
                    $details = collect($errorData['details'])->pluck('description')->implode(', ');
                    $errorMessage .= ': ' . $details;
                } elseif ($errorData && isset($errorData['message'])) {
                    $errorMessage .= ': ' . $errorData['message'];
                } else {
                    $errorMessage .= ' (Status: ' . $response->status() . ')';
                }

                $this->securePaymentService->secureLog('error', 'Erreur détaillée payout PayPal', [
                    'order_id' => $orderId,
                    'vendor_email' => $vendorEmail,
                    'status' => $response->status(),
                    'error_body' => $errorBody,
                    'parsed_error' => $errorMessage
                ]);

                throw new Exception($errorMessage);
            }

            $payoutResponse = $response->json();
            $payoutItemId = $payoutResponse['items'][0]['payout_item_id'] ?? null;
            $batchStatus = $payoutResponse['batch_header']['batch_status'] ?? 'unknown';

            // Log détaillé du succès
            $this->securePaymentService->secureLog('info', 'Payout PayPal créé avec succès', [
                'order_id' => $orderId,
                'vendor_email' => $vendorEmail,
                'payout_batch_id' => $payoutResponse['batch_header']['payout_batch_id'],
                'payout_item_id' => $payoutItemId,
                'batch_status' => $batchStatus,
                'amount' => $amount
            ]);

            return [
                'success' => true,
                'payout_batch_id' => $payoutResponse['batch_header']['payout_batch_id'],
                'payout_item_id' => $payoutItemId,
                'status' => $batchStatus,
                'amount' => $amount
            ];
        } catch (Exception $e) {
            // Log l'erreur avec le maximum de contexte
            $this->securePaymentService->secureLog('error', 'Exception payout PayPal', [
                'order_id' => $orderId,
                'vendor_email' => $vendorEmail,
                'amount' => $amount,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Construire les items PayPal
    */
    private function buildPayPalItems(array $orders): array
    {
        $items = [];

        foreach ($orders as $order) {
            $itemsCount = count($order['items']);
            $description = $itemsCount . ' article' . ($itemsCount > 1 ? 's' : '');

            // Limiter la description pour PayPal (max 127 caractères)
            if ($itemsCount <= 3) {
                $productNames = array_slice(array_column($order['items'], 'product_name'), 0, 3);
                $description = substr(implode(', ', $productNames), 0, 120);
            }

            $items[] = [
                'name' => substr("Commande #{$order['order_number']} - {$order['vendor']['name']}", 0, 127),
                'description' => $description,
                'quantity' => '1',
                'unit_amount' => [
                    'currency_code' => 'EUR',
                    'value' => number_format($order['total_amount'], 2, '.', '')
                ],
                'category' => 'PHYSICAL_GOODS'
            ];
        }

        return $items;
    }

    /**
     * Vérifier la signature du webhook PayPal
    */
    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        try {
            $webhookId = config('services.paypal.webhook_id');

            if (!$webhookId) {
                $this->securePaymentService->secureLog('warning', 'Webhook ID PayPal non configuré');
                return true; // Skip verification si pas configuré
            }

            $token = $this->getAccessToken();

            $verificationData = [
                'auth_algo' => $headers['paypal-auth-algo'][0] ?? '',
                'cert_id' => $headers['paypal-cert-id'][0] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'][0] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'][0] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'][0] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($payload, true)
            ];

            $response = Http::timeout(30)
                ->withToken($token)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $verificationData);

            if (!$response->successful()) {
                $this->securePaymentService->secureLog('warning', 'Échec vérification signature PayPal', [
                    'status' => $response->status()
                ]);
                return false;
            }

            $verification = $response->json();
            return ($verification['verification_status'] ?? '') === 'SUCCESS';

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur vérification signature PayPal', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Traiter un remboursement PayPal
    */
    public function processRefund(string $captureId, ?float $amount = null, string $reason = 'Remboursement demandé'): array
    {
        try {
            $token = $this->getAccessToken();

            $refundData = [
                'note_to_payer' => $reason,
                'invoice_id' => 'REFUND-' . $captureId . '-' . time()
            ];

            if ($amount) {
                $refundData['amount'] = [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => 'EUR'
                ];
            }

            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'PayPal-Request-Id' => 'REFUND-' . $captureId . '-' . time()
                ])
                ->post("{$this->baseUrl}/v2/payments/captures/{$captureId}/refund", $refundData);

            if (!$response->successful()) {
                throw new Exception('Erreur remboursement PayPal: ' . $response->body());
            }

            $refundResponse = $response->json();

            $this->securePaymentService->secureLog('info', 'Remboursement PayPal créé', [
                'refund_id' => $refundResponse['id'],
                'capture_id' => $captureId,
                'amount' => $refundResponse['amount']['value'] ?? $amount
            ]);

            return [
                'success' => true,
                'refund_id' => $refundResponse['id'],
                'amount' => $refundResponse['amount']['value'] ?? $amount,
                'status' => $refundResponse['status']
            ];

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur remboursement PayPal', [
                'capture_id' => $captureId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir l'URL de base PayPal
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Parser les erreurs PayPal pour des messages plus explicites
    */
    private function parsePayPalPayoutError(Exception $e): string
    {
        $message = $e->getMessage();

        // Erreurs courantes PayPal Payouts
        if (strpos($message, 'RECEIVER_UNREGISTERED') !== false) {
            return 'Compte PayPal vendeur non enregistré ou inexistant';
        }

        if (strpos($message, 'RECEIVER_NOT_CONFIRMED') !== false) {
            return 'Compte PayPal vendeur non confirmé';
        }

        if (strpos($message, 'INSUFFICIENT_FUNDS') !== false) {
            return 'Fonds insuffisants sur le compte PayPal plateforme';
        }

        if (strpos($message, 'CURRENCY_NOT_SUPPORTED') !== false) {
            return 'Devise non supportée par le compte PayPal vendeur';
        }

        if (strpos($message, 'TRANSACTION_LIMIT_EXCEEDED') !== false) {
            return 'Limite de transaction PayPal dépassée';
        }

        if (strpos($message, 'REGULATORY_REVIEW_REQUIRED') !== false) {
            return 'Paiement en attente de révision réglementaire PayPal';
        }

        if (strpos($message, 'PAYER_ACCOUNT_LOCKED_OR_CLOSED') !== false) {
            return 'Compte PayPal vendeur verrouillé ou fermé';
        }

        if (strpos($message, 'PAYEE_ACCOUNT_RESTRICTED') !== false) {
            return 'Compte PayPal vendeur restreint';
        }

        if (strpos($message, 'PAYOUT_NOT_SUPPORTED_FOR_RECEIVER') !== false) {
            return 'Paiements entrants non supportés par ce compte PayPal';
        }

        if (strpos($message, 'AMOUNT_MISMATCH') !== false) {
            return 'Montant du paiement incorrect ou invalide';
        }

        if (strpos($message, 'DUPLICATE_REQUEST_ID') !== false) {
            return 'Demande de paiement PayPal dupliquée';
        }

        if (strpos($message, 'INVALID_EMAIL_ADDRESS') !== false) {
            return 'Adresse email PayPal vendeur invalide';
        }

        if (strpos($message, 'AUTHENTICATION_FAILURE') !== false || strpos($message, 'PERMISSION_DENIED') !== false) {
            return 'Erreur d\'authentification PayPal API';
        }

        if (strpos($message, 'RATE_LIMIT_REACHED') !== false) {
            return 'Limite de taux API PayPal atteinte';
        }

        if (strpos($message, 'INTERNAL_SERVICE_ERROR') !== false) {
            return 'Erreur interne PayPal temporaire';
        }

        if (strpos($message, 'PAYOUT_NOT_SUPPORTED_IN_RECEIVING_COUNTRY') !== false) {
            return 'Paiements non supportés dans le pays du vendeur';
        }

        if (strpos($message, 'PAYOUT_AMOUNT_LIMIT_EXCEEDED') !== false) {
            return 'Montant du paiement PayPal trop élevé';
        }

        if (strpos($message, 'PAYOUT_LIMIT_EXCEEDED') !== false) {
            return 'Limite de paiements PayPal quotidienne dépassée';
        }

        // Erreurs API génériques
        if (strpos($message, '401') !== false || strpos($message, 'Unauthorized') !== false) {
            return 'Authentification PayPal API échouée';
        }

        if (strpos($message, '429') !== false || strpos($message, 'Too Many Requests') !== false) {
            return 'Trop de requêtes PayPal API';
        }

        if (strpos($message, '500') !== false || strpos($message, '502') !== false || strpos($message, '503') !== false) {
            return 'Erreur serveur PayPal temporaire';
        }

        // Erreurs de format/validation
        if (strpos($message, 'validation') !== false || strpos($message, 'VALIDATION_ERROR') !== false) {
            return 'Données de paiement PayPal invalides';
        }

        // Si aucune erreur spécifique reconnue, retourner le message original tronqué
        return 'Erreur PayPal: ' . substr($message, 0, 200);
    }
}