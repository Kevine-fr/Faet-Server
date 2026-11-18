<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Transfer;
use Stripe\Webhook;
use Stripe\Account;
use Stripe\AccountLink;

class StripePaymentService
{
    private $securePaymentService;
    private $stripeConnectService;

    public function __construct(SecurePaymentService $securePaymentService, StripeConnectService $stripeConnectService)
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $this->securePaymentService = $securePaymentService;
        $this->stripeConnectService = $stripeConnectService;
    }

    /**
     * Créer une session Stripe Checkout pour plusieurs commandes
    */
    public function createCheckoutSession(array $validatedData, string $sessionId, string $successUrl, string $cancelUrl): array
    {
        try {
            $orders = $validatedData['orders'];
            $totalAmount = $validatedData['total_amount'];

            // Convertir en centimes
            $amountInCents = (int) ($totalAmount * 100);
            
            // Préparer les line items
            $lineItems = $this->buildLineItems($orders);

            // Métadonnées sécurisées
            $metadata = [
                'session_id' => $sessionId,
                'order_count' => count($orders),
                'total_amount' => $totalAmount,
                'platform' => config('app.name'),
                'version' => '2.0'
            ];

            // Créer la session Checkout avec options de sécurité
            $stripeSession = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $successUrl . '?session_id=' . $sessionId,
                'cancel_url' => $cancelUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'metadata' => $metadata,
                'payment_intent_data' => [
                    'metadata' => $metadata,
                    'description' => "Commande(s) " . config('app.name'),
                    'statement_descriptor' => config('app.name'),
                ],
                'customer_creation' => 'if_required',
                'billing_address_collection' => 'required',
                'expires_at' => now()->addHour()->timestamp, // Expiration 1h
                'allow_promotion_codes' => false, // Désactiver les codes promo pour la sécurité
                'payment_method_options' => [
                    'card' => [
                        'request_three_d_secure' => 'automatic', // 3D Secure automatique
                    ],
                ],
            ]);

            return [
                'session_id' => $stripeSession->id,
                'checkout_url' => $stripeSession->url,
                'amount' => $totalAmount,
                'currency' => 'eur',
                'expires_at' => $stripeSession->expires_at
            ];

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur création session Stripe', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'amount' => $validatedData['total_amount']
            ]);
            throw new Exception('Erreur lors de la création de la session Stripe : ' . $e->getMessage());
        }
    }

    /**
     * Traiter le succès du paiement après retour de Stripe Checkout
     */
    public function handleCheckoutSuccess(string $stripeSessionId, array $sessionData): array
    {
        return DB::transaction(function () use ($stripeSessionId, $sessionData) {
            try {
                // Récupérer la session depuis Stripe
                $session = Session::retrieve([
                    'id' => $stripeSessionId,
                    'expand' => ['payment_intent', 'customer'],
                ]);
                
                // Vérifications de sécurité
                if ($session->payment_status !== 'paid') {
                    throw new Exception("Paiement non confirmé. Statut: {$session->payment_status}");
                }

                if ($session->status !== 'complete') {
                    throw new Exception("Session non complète. Statut: {$session->status}");
                }

                // Vérifier les montants
                $expectedAmount = (int) ($sessionData['total_amount'] * 100);
                $actualAmount = $session->amount_total;

                if ($expectedAmount !== $actualAmount) {
                    throw new Exception("Montant incorrect. Attendu: {$expectedAmount}, Reçu: {$actualAmount}");
                }

                $paymentIntent = $session->payment_intent;

                return [
                    'success' => true,
                    'payment_intent_id' => $paymentIntent->id,
                    'amount_received' => $actualAmount / 100,
                    'customer_id' => $session->customer?->id,
                    'payment_method_types' => $paymentIntent->payment_method_types
                ];

                // // Récupérer les commandes liées
                // $orderIds = explode(',', $session->metadata->order_ids);
                // $orders = Order::whereIn('id', $orderIds)->get();

                // $results = [];
                
                // foreach ($orders as $order) {
                //     // Vérifier que la commande n'a pas déjà été traitée
                //     if ($order->payment_status === 'complete') {
                //         continue;
                //     }

                //     // Mettre à jour le statut de la commande
                //     $order->update([
                //         'payment_status' => 'complete',
                //         'payment_method' => 'stripe'
                //     ]);

                //     // Créer la transaction de paiement
                //     $paymentTransaction = Transaction::create([
                //         'order_id' => $order->id,
                //         'vendor_id' => $order->vendor_id,
                //         'type' => 'payment',
                //         'amount' => $order->total_amount,
                //         'status' => 'completed'
                //     ]);

                //     // Effectuer le transfert au vendeur
                //     $transferResult = $this->transferToVendor($order, $session->payment_intent->id);
                    
                //     $results[] = [
                //         'order_id' => $order->id,
                //         'payment_transaction_id' => $paymentTransaction->id,
                //         'transfer_result' => $transferResult
                //     ];
                // }

                // return [
                //     'success' => true,
                //     'session_id' => $sessionId,
                //     'payment_intent_id' => $session->payment_intent->id,
                //     'results' => $results
                // ];

            } catch (Exception $e) {
                $this->securePaymentService->secureLog('error', 'Erreur traitement succès Stripe', [
                    'error' => $e->getMessage(),
                    'stripe_session_id' => $stripeSessionId
                ]);
                
                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        });
    }

    /**
     * Effectuer un transfert vers un vendeur via Stripe Connect
     */
    /**
 * Effectuer un transfert vers un vendeur via Stripe Connect
 */
    public function transferToVendor(array $orders, string $paymentIntentId): array
    {
        Log::info('🚀 DÉBUT transferToVendor', [
            'orders_count' => count($orders),
            'payment_intent_id' => $paymentIntentId
        ]);

        $results = [];
        $breakdown = $this->securePaymentService->calculateTransferBreakdown($orders);

        Log::info('💰 Breakdown calculé', [
            'breakdown' => $breakdown
        ]);

        foreach ($breakdown as $transferData) {
            Log::info('🔄 Traitement transfert', [
                'order_id' => $transferData['order_id'],
                'vendor_id' => $transferData['vendor_id'],
                'vendor_amount' => $transferData['vendor_amount'],
                'platform_fee' => $transferData['platform_fee']
            ]);

            $transactionData = [
                'order_id' => $transferData['order_id'],
                'vendor_id' => $transferData['vendor_id'],
                'type' => 'transfer',
                'amount' => $transferData['vendor_amount'],
                'processed_at' => now()
            ];

            try {
                $order = collect($orders)->firstWhere('id', $transferData['order_id']);
                $vendor = Vendor::find($transferData['vendor_id']);

                Log::info('👤 Vendeur récupéré', [
                    'vendor_id' => $transferData['vendor_id'],
                    'vendor_found' => $vendor ? 'yes' : 'no',
                    'vendor_stripe_id' => $vendor->vendor_stripe_id ?? null,
                    'vendor_email' => $vendor->email ?? null
                ]);

                if (!$vendor || !$vendor->vendor_stripe_id) {
                    Log::error('❌ ÉCHEC: Compte Stripe manquant', [
                        'vendor_id' => $transferData['vendor_id'],
                        'vendor_exists' => $vendor ? 'yes' : 'no',
                        'vendor_stripe_id' => $vendor->vendor_stripe_id ?? null
                    ]);

                    Transaction::create(array_merge($transactionData, [
                        'status' => 'failed',
                        'failure_reason' => 'Compte Stripe vendeur non configuré'
                    ]));

                    $this->securePaymentService->secureLog('warning', 'Compte Stripe vendeur manquant', [
                        'vendor_id' => $transferData['vendor_id'],
                        'order_id' => $transferData['order_id']
                    ]);

                    $results[] = [
                        'order_id' => $transferData['order_id'],
                        'success' => false,
                        'error' => 'Compte Stripe vendeur non configuré'
                    ];
                    continue;
                }

                // Vérifier le statut du compte Stripe
                Log::info('🔍 Vérification statut compte Stripe', [
                    'vendor_id' => $vendor->id,
                    'stripe_account_id' => $vendor->vendor_stripe_id
                ]);

                $accountStatus = $this->stripeConnectService->getAccountStatus($vendor);

                Log::info('📊 Statut compte récupéré', [
                    'vendor_id' => $vendor->id,
                    'status_success' => $accountStatus['success'],
                    'status_data' => $accountStatus['status'] ?? null
                ]);

                if (!$accountStatus['success']) {
                    Log::error('❌ ÉCHEC: Erreur récupération statut', [
                        'vendor_id' => $vendor->id,
                        'error' => $accountStatus['error'] ?? 'unknown'
                    ]);

                    Transaction::create(array_merge($transactionData, [
                        'status' => 'failed',
                        'failure_reason' => 'Erreur vérification compte Stripe: ' . ($accountStatus['error'] ?? 'unknown')
                    ]));

                    $results[] = [
                        'order_id' => $transferData['order_id'],
                        'success' => false,
                        'error' => 'Erreur vérification compte: ' . ($accountStatus['error'] ?? 'unknown')
                    ];
                    continue;
                }

                $overallStatus = $accountStatus['status']['overall_status'] ?? 'unknown';

                Log::info('🎯 Statut global compte', [
                    'vendor_id' => $vendor->id,
                    'overall_status' => $overallStatus,
                    'charges_enabled' => $accountStatus['status']['charges_enabled'] ?? null,
                    'payouts_enabled' => $accountStatus['status']['payouts_enabled'] ?? null,
                    'details_submitted' => $accountStatus['status']['details_submitted'] ?? null
                ]);

                if ($overallStatus !== 'verified') {
                    Log::error('❌ ÉCHEC: Compte non vérifié', [
                        'vendor_id' => $vendor->id,
                        'status' => $overallStatus,
                        'requirements' => $accountStatus['status']['requirements'] ?? null
                    ]);

                    Transaction::create(array_merge($transactionData, [
                        'status' => 'failed',
                        'failure_reason' => "Compte Stripe vendeur non vérifié (status: {$overallStatus})"
                    ]));

                    $results[] = [
                        'order_id' => $transferData['order_id'],
                        'success' => false,
                        'error' => "Compte Stripe vendeur non vérifié (status: {$overallStatus})"
                    ];
                    continue;
                }

                // ✅ Compte vérifié, récupérer le Charge ID
                Log::info('✅ Compte vérifié, récupération du Charge ID', [
                    'vendor_id' => $vendor->id,
                    'payment_intent_id' => $paymentIntentId
                ]);

                // Récupérer le PaymentIntent pour obtenir le Charge ID
                $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
                $chargeId = $paymentIntent->latest_charge;

                if (!$chargeId) {
                    throw new Exception('Aucun charge associé au PaymentIntent');
                }

                Log::info('💳 Charge ID récupéré', [
                    'payment_intent_id' => $paymentIntentId,
                    'charge_id' => $chargeId,
                    'amount_cents' => (int) ($transferData['vendor_amount'] * 100)
                ]);

                // Créer le transfert SANS application_fee_amount pour le moment
                $transfer = Transfer::create([
                    'amount' => (int) ($transferData['vendor_amount'] * 100),
                    'currency' => 'eur',
                    'destination' => $vendor->vendor_stripe_id,
                    'source_transaction' => $chargeId,
                    'metadata' => [
                        'order_id' => $transferData['order_id'],
                        'vendor_id' => $transferData['vendor_id'],
                        'platform_fee' => $transferData['platform_fee'],
                        'gross_amount' => $transferData['gross_amount'],
                        'payment_intent_id' => $paymentIntentId,
                        'session_version' => '2.0'
                    ]
                ]);

                Log::info('🎉 TRANSFERT CRÉÉ AVEC SUCCÈS', [
                    'transfer_id' => $transfer->id,
                    'amount' => $transferData['vendor_amount'],
                    'destination' => $vendor->vendor_stripe_id,
                    'order_id' => $transferData['order_id']
                ]);

                Transaction::create(array_merge($transactionData, [
                    'status' => 'completed',
                    'external_transaction_id' => $transfer->id
                ]));

                $results[] = [
                    'order_id' => $transferData['order_id'],
                    'success' => true,
                    'transfer_id' => $transfer->id,
                    'amount_transferred' => $transferData['vendor_amount'],
                    'platform_fee' => $transferData['platform_fee']
                ];

                $this->securePaymentService->secureLog('info', 'Transfert Stripe Connect réussi', [
                    'order_id' => $transferData['order_id'],
                    'transfer_id' => $transfer->id,
                    'amount' => $transferData['vendor_amount'],
                    'platform_fee' => $transferData['platform_fee']
                ]);
            } catch (Exception $e) {
                $failureReason = $this->parseStripeTransferError($e);

                Log::error('💥 EXCEPTION lors du transfert', [
                    'order_id' => $transferData['order_id'],
                    'vendor_id' => $transferData['vendor_id'],
                    'error_message' => $e->getMessage(),
                    'error_code' => method_exists($e, 'getStripeCode') ? $e->getStripeCode() : null,
                    'failure_reason' => $failureReason,
                    'trace' => $e->getTraceAsString()
                ]);

                Transaction::create(array_merge($transactionData, [
                    'status' => 'failed',
                    'failure_reason' => $failureReason
                ]));

                $results[] = [
                    'order_id' => $transferData['order_id'],
                    'success' => false,
                    'error' => $e->getMessage(),
                    'failure_reason' => $failureReason
                ];

                $this->securePaymentService->secureLog('error', 'Erreur transfert Stripe Connect', [
                    'order_id' => $transferData['order_id'],
                    'vendor_id' => $transferData['vendor_id'],
                    'stripe_account_id' => $vendor->vendor_stripe_id ?? 'non_defini',
                    'error' => $e->getMessage(),
                    'failure_reason' => $failureReason
                ]);
            }
        }

        Log::info('🏁 FIN transferToVendor', [
            'total_transfers' => count($results),
            'successful' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => $results
        ]);

        return $results;
    }

    /**
     * Vérifier la signature du webhook
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            $endpointSecret = config('services.stripe.webhook_secret');
            
            if (!$endpointSecret) {
                $this->securePaymentService->secureLog('warning', 'Secret webhook Stripe non configuré');
                return false;
            }

            Webhook::constructEvent($payload, $signature, $endpointSecret);
            return true;

        } catch (SignatureVerificationException $e) {
            $this->securePaymentService->secureLog('warning', 'Signature webhook Stripe invalide', [
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur vérification webhook Stripe', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Traiter un remboursement
    */
    public function processRefund(string $paymentIntentId, ?float $amount = null, string $reason = null): array
    {
        try {
            $refundData = ['payment_intent' => $paymentIntentId];
            
            if ($amount) {
                $refundData['amount'] = (int) ($amount * 100);
            }
            
            if ($reason) {
                $refundData['reason'] = 'requested_by_customer';
                $refundData['metadata'] = ['reason' => $reason];
            }

            $refund = Refund::create($refundData);

            $this->securePaymentService->secureLog('info', 'Remboursement Stripe créé', [
                'refund_id' => $refund->id,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $refund->amount / 100
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'amount' => $refund->amount / 100,
                'status' => $refund->status,
                'reason' => $refund->reason
            ];

        } catch (Exception $e) {
            $this->securePaymentService->secureLog('error', 'Erreur remboursement Stripe', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
    * Construire les line items pour Stripe
    */
    private function buildLineItems(array $orders): array
    {
        $lineItems = [];
        
        foreach ($orders as $order) {
            $itemsCount = count($order['items']);
            $description = $itemsCount . ' article' . ($itemsCount > 1 ? 's' : '');
            
            // Ajouter des détails sur les produits
            if ($itemsCount <= 3) {
                $productNames = array_slice(array_column($order['items'], 'product_name'), 0, 3);
                $description = implode(', ', $productNames);
            }
            
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => "Commande #{$order['order_number']} - {$order['vendor']['name']}",
                        'description' => $description,
                        'metadata' => [
                            'order_id' => $order['id'],
                            'vendor_id' => $order['vendor']['id']
                        ]
                    ],
                    'unit_amount' => (int) ($order['total_amount'] * 100),
                ],
                'quantity' => 1,
            ];
        }

        return $lineItems;
    }

    /**
     * Obtenir les détails d'un paiement
     */
    public function getPaymentDetails(string $paymentIntentId): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            return [
                'success' => true,
                'data' => [
                    'id' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'created' => $paymentIntent->created,
                    'description' => $paymentIntent->description,
                    'metadata' => $paymentIntent->metadata->toArray()
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /* Parser les erreurs Stripe pour des messages plus explicites
     */
    private function parseStripeTransferError(Exception $e): string
    {
        $message = $e->getMessage();

        // Erreurs courantes Stripe
        if (strpos($message, 'account_invalid') !== false) {
            return 'Compte Stripe vendeur invalide ou désactivé';
        }

        if (strpos($message, 'account_not_found') !== false) {
            return 'Compte Stripe vendeur introuvable';
        }

        if (strpos($message, 'insufficient_funds') !== false) {
            return 'Fonds insuffisants sur le compte plateforme';
        }

        if (strpos($message, 'transfer_not_allowed') !== false) {
            return 'Transfert non autorisé vers ce compte vendeur';
        }

        if (strpos($message, 'currency_not_supported') !== false) {
            return 'Devise non supportée par le compte vendeur';
        }

        if (strpos($message, 'amount_too_small') !== false) {
            return 'Montant du transfert trop petit (minimum requis)';
        }

        if (strpos($message, 'amount_too_large') !== false) {
            return 'Montant du transfert trop important';
        }

        if (strpos($message, 'api_key') !== false) {
            return 'Erreur d\'authentification Stripe API';
        }

        if (strpos($message, 'rate_limit') !== false) {
            return 'Limite de taux API Stripe atteinte';
        }

        if (strpos($message, 'processing_error') !== false) {
            return 'Erreur de traitement Stripe temporaire';
        }

        // Si aucune erreur spécifique reconnue, retourner le message original tronqué
        return 'Erreur Stripe: ' . substr($message, 0, 200);
    }

}