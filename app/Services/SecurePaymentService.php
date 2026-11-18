<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentSession;
use App\Models\Transaction;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SecurePaymentService
{
    /**
     * Valider et sécuriser les données de paiement
    */
    public function validateAndSecurePayment(array $orders, int $userId): array
    {
        return DB::transaction(function () use ($orders, $userId) {
            $validatedOrders = [];
            $totalAmount = 0;

            foreach ($orders as $orderData) {
                $order = Order::where('id', $orderData['id'])
                    ->where('user_id', $userId)
                    ->where('payment_status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    throw new Exception("Commande {$orderData['id']} introuvable ou déjà traitée");
                }

                // Recalculer le montant côté serveur
                $calculatedAmount = $this->calculateOrderTotal($order);
                
                if (abs($calculatedAmount - $orderData['total_amount']) > 0.01) {
                    throw new Exception("Montant invalide pour la commande {$order->id}. Attendu: {$calculatedAmount}, Reçu: {$orderData['total_amount']}");
                }

                // Vérifier l'intégrité du vendeur
                if (!$order->vendor || $order->vendor->id != $orderData['vendor']['id']) {
                    throw new Exception("Données vendeur invalides pour la commande {$order->id}");
                }

                $validatedOrders[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => $calculatedAmount,
                    'vendor' => [
                        'id' => $order->vendor->id,
                        'name' => $order->vendor->name,
                        'stripe_account_id' => $order->vendor->stripe_account_id,
                        'paypal_email' => $order->vendor->paypal_email,
                    ],
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'product_name' => $item->product->name ?? 'Produit supprimé'
                        ];
                    })->toArray()
                ];

                $totalAmount += $calculatedAmount;
            }

            return [
                'orders' => $validatedOrders,
                'total_amount' => $totalAmount,
                'orders_count' => count($validatedOrders)
            ];
        });
    }

    /**
     * Calculer le montant total d'une commande (source de vérité)
    */
    private function calculateOrderTotal(Order $order): float
    {
        $subtotal = $order->items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        Log::info('Calcul du montant total', [
            'subtotal' => $subtotal,
        ]);

        $shipping = $order->shipping_cost ?? 0;
        $tax = $order->tax_amount ?? 0;
        $discount = $order->discount_amount ?? 0;

        return max(0, $subtotal + $shipping + $tax - $discount);
    }

    /**
     * Créer une session de paiement sécurisée
    */
    public function createSecurePaymentSession(array $validatedData, string $paymentMethod, array $urls): PaymentSession
    {
        $sessionId = Str::uuid();
        $encryptionKey = config('app.key');
        
        // Chiffrer les données sensibles
        $encryptedData = encrypt([
            'orders' => $validatedData['orders'],
            'total_amount' => $validatedData['total_amount'],
            'urls' => $urls,
            'created_at' => now()->toISOString(),
            'expires_at' => now()->addHour()->toISOString()
        ]);

        return PaymentSession::create([
            'id' => $sessionId,
            'user_id' => auth()->id(),
            'payment_method' => $paymentMethod,
            'total_amount' => $validatedData['total_amount'],
            'orders_count' => $validatedData['orders_count'],
            'encrypted_data' => $encryptedData,
            'status' => 'pending',
            'expires_at' => now()->addHour()
        ]);
    }

    /**
     * Récupérer et valider une session de paiement
    */
    public function getSecurePaymentSession(string $sessionId, int $userId, bool $allowProcessed = false): array
    {
        Log::info('Tentative récupération session', [
            'session_id' => $sessionId,
            'user_id' => $userId
        ]);

        $query = PaymentSession::where('id', $sessionId)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now());

        if (!$allowProcessed) {
            $query->where('status', 'pending');
        }

        $session = $query->first();

        if (!$session) {
            Log::warning('Session introuvable', ['session_id' => $sessionId]);
            throw new Exception('Session de paiement introuvable');
        }

        Log::info('Session trouvée', [
            'session_id' => $sessionId,
            'status' => $session->status,
            'expires_at' => $session->expires_at,
            'is_expired' => $session->expires_at < now()
        ]);

        // Si la session est déjà traitée, retourner les données sans retraitement
        if ($session->status === 'completed' || $session->status === 'processed') {
            return [
                'already_processed' => true,
                'session_status' => $session->status,
                'message' => 'Paiement déjà confirmé'
            ];
        }

        try {
            $decryptedData = decrypt($session->encrypted_data);
            
            // Vérifier l'expiration
            if (now() > $decryptedData['expires_at']) {
                throw new Exception('Session de paiement expirée');
            }

            return $decryptedData;
        } catch (Exception $e) {
            throw new Exception('Impossible de décrypter les données de session');
        }
    }

    /**
     * Marquer les commandes comme traitées de manière atomique
    */
    public function markOrdersAsProcessed(array $orderIds, string $paymentMethod, string $status = 'complete'): void
    {
        DB::transaction(function () use ($orderIds, $paymentMethod, $status) {
            $updatedCount = Order::whereIn('id', $orderIds)
                ->where('payment_status', 'pending')
                ->update([
                    'payment_status' => $status,
                    'payment_method' => $paymentMethod
                ]);

            if ($updatedCount !== count($orderIds)) {
                throw new Exception('Certaines commandes n\'ont pas pu être mises à jour');
            }
        });
    }

    /**
     * Créer les transactions de paiement
    */
    public function createPaymentTransactions(array $orders, string $externalTransactionId = null): array
    {
        $transactions = [];

        foreach ($orders as $order) {
            $transaction = Transaction::create([
                'order_id' => $order['id'],
                'vendor_id' => $order['vendor']['id'],
                'type' => 'payment',
                'amount' => $order['total_amount'],
                'status' => 'completed',
                'external_transaction_id' => $externalTransactionId,
                'processed_at' => now()
            ]);

            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * Calculer les frais de plateforme et montants de transfert
    */
    public function calculateTransferBreakdown(array $orders): array
    {
        $breakdown = [];
        $platformFeeRate = config('payment.platform_fee_rate', 0.07);

        foreach ($orders as $order) {
            $orderAmount = $order['total_amount'];
            $platformFee = $orderAmount * $platformFeeRate;
            $vendorAmount = $orderAmount - $platformFee;

            $breakdown[] = [
                'order_id' => $order['id'],
                'vendor_id' => $order['vendor']['id'],
                'gross_amount' => $orderAmount,
                'platform_fee' => round($platformFee, 2),
                'vendor_amount' => round($vendorAmount, 2)
            ];
        }

        return $breakdown;
    }

    /**
     * Logger sécurisé (sans données sensibles)
    */
    public function secureLog(string $level, string $message, array $context = []): void
    {
        // Nettoyer les données sensibles
        $cleanContext = $this->sanitizeLogData($context);
        
        Log::log($level, $message, array_merge($cleanContext, [
            'user_id' => auth()->id(),
            'timestamp' => now()->toISOString(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]));
    }

    /**
     * Nettoyer les données pour les logs
    */
    private function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'encrypted_data', 'paypal_email'];
        
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '[REDACTED]';
            }
        });

        return $data;
    }

    /**
     * Vérifier les tentatives de paiement suspectes
    */
    public function checkSuspiciousActivity(int $userId): void
    {
        $cacheKey = "payment_attempts_{$userId}";
        $attempts = Cache::get($cacheKey, 0);
        
        if ($attempts >= 5) {
            $this->secureLog('warning', 'Tentatives de paiement suspectes', [
                'user_id' => $userId,
                'attempts' => $attempts
            ]);
            
            throw new Exception('Trop de tentatives de paiement. Veuillez réessayer dans quelques minutes.');
        }
        
        Cache::put($cacheKey, $attempts + 1, now()->addMinutes(15));
    }

    /**
     * Réinitialiser le compteur de tentatives après succès
    */
    public function resetSuspiciousActivityCounter(int $userId): void
    {
        Cache::forget("payment_attempts_{$userId}");
    }
}