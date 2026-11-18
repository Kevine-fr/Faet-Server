<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\OrderCancellationNotification;
use App\Notifications\OrderValidationNotification;
use App\Notifications\OrderReadyNotification;
use App\Notifications\OrderReminderNotification;
use App\Notifications\OrderRefundNotification;
use App\Notifications\OrderPickedUpNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Récupère les données financières par statut pour un vendeur
     */
    public function getFinanceData(int $vendorId, ?string $period = null, ?string $startDate = null, ?string $endDate = null): array
    {
        // Ajoutez des logs pour déboguer
        Log::info("getFinanceData appelé avec vendorId: $vendorId, period: $period");

        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);

        Log::info("Plage de dates: " . ($start ? $start->toISOString() : 'null') . " à " . ($end ? $end->toISOString() : 'null'));

        // Vérifiez d'abord s'il y a des commandes pour ce vendeur
        $totalOrders = Order::where('vendor_id', $vendorId)->count();
        Log::info("Total des commandes pour le vendeur: $totalOrders");

        // Statuts suivis - inclure tous les statuts pour avoir une vue complète
        $statuses = [
            'to_be_collected' => 'À retirer',
            'collected'       => 'Récupéré',
            'abandoned'       => 'Abandonnées',
        ];

        // Récupération des données agrégées
        $financeData = Order::select(
            'status',
            DB::raw('COUNT(*) as count'),
            DB::raw('SUM(total_amount) as total_amount')
        )
            ->where('vendor_id', $vendorId)
            ->whereIn('status', array_keys($statuses))
            ->when($start && $end, fn($q) => $q->whereBetween('created_at', [$start, $end]))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Construction du résultat
        $result = [];
        foreach ($statuses as $status => $label) {
            $result[$status] = [
                'status' => $status,
                'label'  => $label,
                'count'  => (int) ($financeData[$status]->count ?? 0),
                'amount' => (float) ($financeData[$status]->total_amount ?? 0),
            ];
        }

        // Calcul des remboursements (commandes remboursées avec montant > 0)
        // IMPORTANT: Les remboursements ne doivent PAS être soustraits du chiffre d'affaires
        // car on ne compte déjà QUE les statuts 'in_preparation', 'to_be_collected', 'collected'
        // qui ne peuvent pas être remboursés
        $refunds = Order::where('vendor_id', $vendorId)
            ->where('status', 'refunded')
            ->where('total_amount', '>', 0)
            ->when($start && $end, fn($q) => $q->whereBetween('created_at', [$start, $end]))
            ->sum('total_amount');
            
        Log::info("Calcul des remboursements:", [
            'refunds_amount' => $refunds,
            'date_range' => $start && $end ? [$start->toISOString(), $end->toISOString()] : 'all'
        ]);

        // Calcul du chiffre d'affaires (uniquement: en préparation, à retirer, récupéré)
        // Exclure: annulées, remboursées, abandonnées
        $baseQuery = Order::where('vendor_id', $vendorId)
            ->where('payment_status', 'complete')
            ->whereIn('status', ['in_preparation', 'to_be_collected', 'collected'])
            ->where('total_amount', '>', 0);
            
        if ($start && $end) {
            $baseQuery->whereBetween('created_at', [$start, $end]);
        }
        
        $revenue = (clone $baseQuery)->sum('total_amount');
        
        // Logs détaillés pour debug - Récupérer les commandes individuelles
        $revenueCount = (clone $baseQuery)->count();
        $revenueByStatus = (clone $baseQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as amount'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        
        // Récupérer toutes les commandes individuelles pour debug
        $allMatchingOrders = (clone $baseQuery)
            ->select('id', 'order_number', 'status', 'payment_status', 'total_amount', 'created_at')
            ->get()
            ->map(function($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'total_amount' => $order->total_amount,
                    'created_at' => $order->created_at->toISOString()
                ];
            });
            
        Log::info("Calcul revenue détaillé:", [
            'total_revenue' => $revenue,
            'total_count' => $revenueCount,
            'by_status' => $revenueByStatus->map(function($item) {
                return ['count' => $item->count, 'amount' => $item->amount];
            })->toArray(),
            'date_range' => $start && $end ? [$start->toISOString(), $end->toISOString()] : 'all',
            'all_orders' => $allMatchingOrders->toArray()
        ]);

        // Totaux par statut (pour la séparation visuelle)
        $totalCount  = array_sum(array_column($result, 'count'));
        $totalAmount = array_sum(array_column($result, 'amount'));

        // Calcul du panier moyen (sur les mêmes commandes que le revenue)
        $ordersWithAmount = (clone $baseQuery)->count();

        // Le revenue n'inclut QUE les statuts 'in_preparation', 'to_be_collected', 'collected'
        // qui ne peuvent pas être remboursés, donc pas besoin de soustraire les refunds
        // On garde les refunds pour l'affichage séparé seulement
        $netRevenue = $revenue; // Pas de soustraction car les commandes comptées ne sont jamais remboursées
        
        Log::info("Calcul final:", [
            'revenue' => $revenue,
            'refunds' => $refunds,
            'netRevenue' => $netRevenue,
            'ordersWithAmount' => $ordersWithAmount,
            'note' => 'Les refunds ne sont pas soustraits car les commandes comptées (in_preparation, to_be_collected, collected) ne sont jamais remboursées'
        ]);
        $averageBasket = $ordersWithAmount > 0 ? $netRevenue / $ordersWithAmount : 0;

        $result = [
            'by_status' => $result,
            'totals' => [
                'count'  => $totalCount,
                'amount' => round($netRevenue, 2),
            ],
            'averageBasket' => round($averageBasket, 2),
            'refunds' => round($refunds, 2),
            'period' => [
                'start' => $start?->toISOString(),
                'end'   => $end?->toISOString(),
                'type'  => $period,
            ],
        ];

        Log::info("Résultat final:", $result);
        return $result;
    }

    /**
     * Récupère les commandes validées et remboursées pour la page finance
     */
    public function getOrderValidatedAndRefunded(int $vendorId, ?string $period = null, ?string $startDate = null, ?string $endDate = null, ?string $search = null, ?string $status = null): array
    {
        Log::info("getOrderValidatedAndRefunded appelé avec vendorId: $vendorId, period: $period");

        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);

        Log::info("Plage de dates: " . ($start ? $start->toISOString() : 'null') . " à " . ($end ? $end->toISOString() : 'null'));

        // Statuts des commandes à récupérer
        $statuses = [
            'to_be_collected' => 'À retirer',
            'collected'       => 'Récupéré',
            'abandoned'       => 'Abandonnées',
            'refunded'        => 'Remboursées',
        ];

        // Récupération des commandes avec les détails utilisateur
        $query = Order::with(['user:id,first_name,last_name,email'])
            ->where('vendor_id', $vendorId);

        // Filtrage par statut si spécifié
        if ($status && $status !== 'all' && isset($statuses[$status])) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', array_keys($statuses));
        }

        // Filtrage par période
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Recherche par numéro de commande ou nom du client
        if ($search) {
            // Nettoyer la recherche (enlever # si présent)
            $cleanSearch = ltrim($search, '#');

            $query->where(function ($q) use ($search, $cleanSearch) {
                $q->where('order_number', 'LIKE', "%{$cleanSearch}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where(function ($userSub) use ($search) {
                            // Recherche dans le prénom
                            $userSub->where('first_name', 'LIKE', "%{$search}%")
                                // Recherche dans le nom
                                ->orWhere('last_name', 'LIKE', "%{$search}%")
                                // Recherche dans l'email
                                ->orWhere('email', 'LIKE', "%{$search}%")
                                // Recherche dans le nom complet (prénom + nom)
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"])
                                // Recherche dans le nom complet inversé (nom + prénom)
                                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$search}%"]);
                        });
                    });
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->get()
            ->map(function ($order) use ($statuses) {
                return [
                    'id' => $order->id,
                    'type' => $order->status === 'refunded' ? 'refund' : 'sale',
                    'amount' => $order->total_amount,
                    'status' => $order->status,
                    'date' => $order->created_at,
                    'customer' => [
                        'name' => $order->user ? ($order->user->first_name . ' ' . $order->user->last_name) : 'Client inconnu',
                        'email' => $order->user->email ?? 'N/A',
                    ],
                    'order_number' => '#' . $order->order_number,
                ];
            });

        // Calcul des totaux par type
        $totals = [
            'sales' => $orders->where('type', 'sale')->sum('amount'),
            'refunds' => $orders->where('type', 'refund')->sum('amount'),
            'count' => $orders->count(),
        ];

        $result = [
            'orders' => $orders,
            'totals' => $totals,
            'period' => [
                'start' => $start?->toISOString(),
                'end'   => $end?->toISOString(),
                'type'  => $period,
            ],
        ];

        Log::info("Résultat getOrderValidatedAndRefunded:", $result);
        return $result;
    }

    /**
     * Résout la plage de dates en fonction des paramètres
     */
    private function resolveDateRange(?string $period, ?string $startDate, ?string $endDate): array
    {
        if ($startDate && $endDate) {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->endOfDay();
                return [$start, $end];
            } catch (\Throwable $e) {
                return [null, null];
            }
        }

        if ($period === 'today') {
            return [Carbon::today(), Carbon::now()];
        }
        if ($period === 'week') {
            return [Carbon::now()->startOfWeek(), Carbon::now()];
        }
        if ($period === 'month') {
            return [Carbon::now()->startOfMonth(), Carbon::now()];
        }
        return [null, null];
    }

    /**
     * Récupère la liste paginée des commandes avec filtres et statistiques
     */
    public function getOrdersList(int $vendorId, array $filters = []): array
    {
        $perPage = max(1, (int) ($filters['per_page'] ?? 10));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $status = $filters['status'] ?? null;
        $paymentMethod = $filters['paymentMethod'] ?? null;
        $period = $filters['period'] ?? null;
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $searchQuery = $filters['q'] ?? null;
        $orderNumber = $filters['order_number'] ?? null;
        $include = $filters['include'] ?? [];

        // Validation des statuts
        $allowedStatuses = ['pending_validation', 'in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned'];
        if ($status && !in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Statut invalide');
        }

        // Validation des moyens de paiement
        $allowedPayments = ['paypal', 'cb', 'stripe'];
        if ($paymentMethod && !in_array($paymentMethod, $allowedPayments, true)) {
            throw new \InvalidArgumentException('Moyen de paiement invalide');
        }

        [$start, $end] = $this->resolveDateRange($period, $startDate, $endDate);

        // Construction de la requête de base
        $baseQuery = $this->buildOrdersQuery($vendorId, $status, $paymentMethod, $start, $end, $orderNumber, $searchQuery);

        // Récupération des commandes paginées
        $paginator = $this->getPaginatedOrders($baseQuery, $perPage, $page, $include);

        // Récupération des compteurs et du CA
        $counts = $this->getOrdersCounts($vendorId, $start, $end);
        $totalRevenue = $this->getTotalRevenue($vendorId, $start, $end);

        return [
            'orders' => $paginator,
            'counts' => $counts,
            'totalRevenue' => $totalRevenue,
        ];
    }

    /**
     * Construit la requête de base pour les commandes
     */
    private function buildOrdersQuery(int $vendorId, ?string $status, ?string $paymentMethod, ?Carbon $start, ?Carbon $end, ?string $orderNumber, ?string $searchQuery)
    {
        $with = ['user:id,first_name,last_name,email'];

        $query = Order::with($with)
            ->where('vendor_id', $vendorId)
            ->where('payment_status', 'complete');

        // Filtres conditionnels
        $query->when($start && $end, function ($q) use ($start, $end) {
            $q->whereBetween('created_at', [$start, $end]);
        })
            ->when($status, function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($orderNumber, function ($q) use ($orderNumber) {
                // Nettoyer le numéro de commande (enlever # si présent)
                $cleanOrderNumber = ltrim($orderNumber, '#');
                $q->where('order_number', 'LIKE', "%{$cleanOrderNumber}%");
            })
            ->when($searchQuery, function ($q) use ($searchQuery) {
                $q->where(function ($sub) use ($searchQuery) {
                    // Nettoyer la recherche (enlever # si présent)
                    $cleanSearch = ltrim($searchQuery, '#');

                    // Recherche par numéro de commande
                    $sub->orWhere('order_number', 'LIKE', "%{$cleanSearch}%");

                    // Recherche par client
                    $sub->orWhereHas('user', function ($u) use ($searchQuery) {
                        $u->where(function ($userSub) use ($searchQuery) {
                            // Recherche dans le prénom
                            $userSub->where('first_name', 'LIKE', "%{$searchQuery}%")
                                // Recherche dans le nom
                                ->orWhere('last_name', 'LIKE', "%{$searchQuery}%")
                                // Recherche dans l'email
                                ->orWhere('email', 'LIKE', "%{$searchQuery}%")
                                // Recherche dans le nom complet (prénom + nom)
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchQuery}%"])
                                // Recherche dans le nom complet inversé (nom + prénom)
                                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$searchQuery}%"]);
                        });
                    });
                });
            })
            ->when($paymentMethod, function ($q) use ($paymentMethod) {
                $q->where('payment_method', $paymentMethod);
            });

        return $query;
    }

    /**
     * Récupère les commandes paginées avec transformation des données
     */
    private function getPaginatedOrders($baseQuery, int $perPage, int $page, array $include): LengthAwarePaginator
    {
        $with = ['user:id,first_name,last_name,email'];
        if (in_array('items', $include, true)) {
            $with[] = 'items.productVariant';
            $with[] = 'items.product:id,name';
        }

        $baseQuery->with($with);

        return (clone $baseQuery)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(function ($order) use ($include) {
                return $this->transformOrderData($order, $include);
            });
    }

    /**
     * Transforme les données d'une commande
     */
    private function transformOrderData($order, array $include): array
    {
        $base = [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'createdAt' => $order->created_at->toISOString(),
            'status' => $order->status,
            'amount' => (float) $order->total_amount,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status,
            'customer' => [
                'name' => $order->user ? ($order->user->first_name . ' ' . $order->user->last_name) : 'Client inconnu',
                'email' => $order->user->email ?? 'N/A',
            ],
        ];

        if (in_array('items', $include, true)) {
            $base['items'] = $order->items->map(function ($item) {
                $variantPrice = data_get($item, 'productVariant.price');
                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'productVariantId' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'unitPrice' => (float) $item->unit_price,
                    'lineTotal' => (float) $item->unit_price * (int) $item->quantity,
                    'product' => [
                        'name' => optional($item->product)->name,
                    ],
                    'variant' => [
                        'sku' => data_get($item, 'productVariant.sku'),
                        'size' => data_get($item, 'productVariant.size'),
                        'color' => data_get($item, 'productVariant.color'),
                        'price' => $variantPrice !== null ? (float) $variantPrice : null,
                    ],
                ];
            })->values();
        }

        return $base;
    }

    /**
     * Récupère les compteurs de commandes par statut
     */
    private function getOrdersCounts(int $vendorId, ?Carbon $start, ?Carbon $end): array
    {
        $counts = Order::select('status', DB::raw('COUNT(*) as count'))
            ->where('vendor_id', $vendorId)
            ->where('payment_status', 'complete')
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end]);
            })
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $normalizedCounts = [
            'pending_validation' => $counts['pending_validation'] ?? 0,
            'in_preparation' => $counts['in_preparation'] ?? 0,
            'to_be_collected' => $counts['to_be_collected'] ?? 0,
            'collected' => $counts['collected'] ?? 0,
            'canceled' => $counts['canceled'] ?? 0,
            'abandoned' => $counts['abandoned'] ?? 0,
        ];
        $normalizedCounts['all'] = array_sum($normalizedCounts);

        return $normalizedCounts;
    }

    /**
     * Calcule le chiffre d'affaires total
     */
    private function getTotalRevenue(int $vendorId, ?Carbon $start, ?Carbon $end): float
    {
        $revenueQuery = Order::where('vendor_id', $vendorId)
            ->where('payment_status', 'complete');

        if ($start && $end) {
            $revenueQuery->whereBetween('created_at', [$start, $end]);
        }

        return (float) $revenueQuery->sum('total_amount');
    }

    /**
     * Marquer une commande comme prête
     */
    public function markAsReady(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'in_preparation') {
            throw new \InvalidArgumentException('Cette commande ne peut pas être marquée comme prête');
        }

        $order->update(['status' => 'to_be_collected']);

        return [
            'success' => true,
            'message' => 'Commande marquée comme prête',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];
    }

    /**
     * Marquer une commande comme récupérée
     */
    public function markAsCollected(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if (!in_array($order->status, ['to_be_collected', 'abandoned'])) {
            throw new \InvalidArgumentException('Seules les commandes à retirer ou abandonnées peuvent être marquées comme récupérées');
        }

        $order->update(['status' => 'collected']);

        return [
            'success' => true,
            'message' => 'Commande marquée comme récupérée',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];
    }

    /**
     * Marquer une commande comme abandonnée
     */
    public function markAsAbandoned(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'to_be_collected') {
            throw new \InvalidArgumentException('Cette commande ne peut pas être marquée comme abandonnée');
        }

        $order->update(['status' => 'abandoned']);

        return [
            'success' => true,
            'message' => 'Commande marquée comme abandonnée',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];
    }

    /**
     * Rembourser une commande
     */
    public function refundOrder(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'to_be_collected') {
            throw new \InvalidArgumentException('Cette commande ne peut pas être remboursée');
        }

        $order->update(['status' => 'refunded']);

        return [
            'success' => true,
            'message' => 'Commande remboursée avec succès',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];
    }

    /**
     * Marquer une commande comme remboursée avec notification email
     */
    public function markAsRefunded(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'collected') {
            throw new \InvalidArgumentException('Seules les commandes récupérées peuvent être remboursées');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // S'assurer que les relations sont chargées
        $order->load('user');

        // Log avant les opérations
        Log::info('Tentative de remboursement de commande', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'current_payment_status' => $order->payment_status,
            'vendor_id' => $vendorId
        ]);

        // 1. ENVOYER L'EMAIL D'ABORD
        try {
            $this->sendRefundConfirmationEmail($order, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email de remboursement', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw new \InvalidArgumentException('Impossible d\'envoyer l\'email de confirmation de remboursement. La commande n\'a pas été remboursée. Erreur: ' . $e->getMessage());
        }

        // 2. SI EMAIL RÉUSSI : Mettre à jour le statut et payment_status
        $refundDate = now()->format('Y-m-d H:i:s');

        Log::info('Tentative de mise à jour pour remboursement', [
            'order_id' => $order->id,
            'refund_date' => $refundDate
        ]);

        $updated = $order->update([
            'status' => 'refunded',
            'payment_status' => 'refunded',
            'refunded_at' => $refundDate
        ]);

        Log::info('Commande remboursée avec succès', [
            'order_id' => $order->id,
            'updated' => $updated,
            'new_status' => $order->fresh()->status,
            'new_payment_status' => $order->fresh()->payment_status,
            'refunded_at' => $refundDate
        ]);

        return [
            'success' => true,
            'message' => 'Commande remboursée avec succès et client notifié',
            'data' => [
                'id' => $order->id,
                'status' => 'refunded',
                'payment_status' => 'refunded',
                'refunded_at' => $refundDate,
                'refund_confirmed' => true,
            ],
        ];
    }

    /**
     * Annuler une commande
     */
    public function cancelOrder(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'in_preparation') {
            throw new \InvalidArgumentException('Cette commande ne peut pas être annulée');
        }

        $order->update(['status' => 'canceled']);

        return [
            'success' => true,
            'message' => 'Commande annulée avec succès',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];
    }

    /**
     * Marquer une commande comme en préparation (validation)
     */
    public function markAsInPreparation(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'pending_validation') {
            throw new \InvalidArgumentException('Seules les commandes en attente de validation peuvent être mises en préparation');
        }

        // Charger les items de la commande avec les variantes de produits
        $order->load(['items.productVariant', 'items.product']);

        $stockIssues = [];
        $updatedItems = [];
        $hasStockIssues = false;

        // Vérifier le stock pour chaque item de la commande
        foreach ($order->items as $item) {
            if (!$item->productVariant) {
                // Si pas de variante, on ne peut pas vérifier le stock
                continue;
            }

            $requestedQuantity = $item->quantity;
            $availableStock = $item->productVariant->stock_quantity;

            if ($availableStock <= 0) {
                // Rupture totale de stock
                $stockIssues[] = [
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'variant_info' => $this->getVariantInfo($item->productVariant),
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => 0,
                    'shortage' => $requestedQuantity,
                    'status' => 'out_of_stock'
                ];
                $hasStockIssues = true;

                // Mettre la quantité à 0 pour cet item
                $item->update(['quantity' => 0]);
                $updatedItems[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'original_quantity' => $requestedQuantity,
                    'new_quantity' => 0,
                    'reason' => 'Rupture de stock'
                ];
            } elseif ($availableStock < $requestedQuantity) {
                // Stock insuffisant
                $shortage = $requestedQuantity - $availableStock;
                $stockIssues[] = [
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'variant_info' => $this->getVariantInfo($item->productVariant),
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => $availableStock,
                    'shortage' => $shortage,
                    'status' => 'partial_stock'
                ];
                $hasStockIssues = true;

                // Ajuster la quantité à la quantité disponible
                $item->update(['quantity' => $availableStock]);
                $updatedItems[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'original_quantity' => $requestedQuantity,
                    'new_quantity' => $availableStock,
                    'reason' => "Stock insuffisant (manque {$shortage} unité(s))"
                ];

                // Mettre à jour le stock à 0
                $item->productVariant->update(['stock_quantity' => 0]);
            } else {
                // Stock suffisant - soustraire la quantité commandée
                $newStock = $availableStock - $requestedQuantity;
                $item->productVariant->update(['stock_quantity' => $newStock]);
            }
        }

        // Mettre à jour le statut de la commande
        $order->update(['status' => 'in_preparation']);

        // Préparer la réponse
        $response = [
            'success' => true,
            'message' => $hasStockIssues
                ? 'Commande mise en préparation avec ajustements de stock'
                : 'Commande validée et mise en préparation avec succès',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
            ],
        ];

        // Ajouter les informations sur les problèmes de stock si nécessaire
        if ($hasStockIssues) {
            $response['stock_issues'] = $stockIssues;
            $response['updated_items'] = $updatedItems;
            $response['message'] .= '. Vérifiez les ajustements de quantité ci-dessous.';
        }

        return $response;
    }

    /**
     * Obtenir les informations de la variante pour l'affichage
     */
    private function getVariantInfo(ProductVariant $variant): string
    {
        $info = [];
        if ($variant->size) $info[] = "Taille: {$variant->size}";
        if ($variant->color) $info[] = "Couleur: {$variant->color}";
        if ($variant->sku) $info[] = "SKU: {$variant->sku}";

        return $info ? implode(', ', $info) : 'Variante standard';
    }

    /**
     * Valider le stock pour une commande sans l'appliquer
     */
    private function validateStockForOrder(Order $order): array
    {
        // Charger les items de la commande avec les variantes de produits
        $order->load(['items.productVariant', 'items.product']);

        $stockIssues = [];
        $updatedItems = [];
        $hasStockIssues = false;
        $hasCriticalStockIssues = false;
        $criticalProducts = [];

        // Vérifier le stock pour chaque item de la commande
        foreach ($order->items as $item) {
            if (!$item->productVariant) {
                // Si pas de variante, on ne peut pas vérifier le stock
                continue;
            }

            $requestedQuantity = $item->quantity;
            $availableStock = $item->productVariant->stock_quantity;

            if ($availableStock <= 0) {
                // Rupture totale de stock - CRITIQUE
                $stockIssues[] = [
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'variant_info' => $this->getVariantInfo($item->productVariant),
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => 0,
                    'shortage' => $requestedQuantity,
                    'status' => 'out_of_stock'
                ];
                $hasStockIssues = true;
                $hasCriticalStockIssues = true;
                $criticalProducts[] = $item->product->name ?? 'Produit inconnu';

                $updatedItems[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'original_quantity' => $requestedQuantity,
                    'new_quantity' => 0,
                    'reason' => 'Rupture de stock'
                ];
            } elseif ($availableStock < $requestedQuantity) {
                // Stock insuffisant - NON CRITIQUE (on peut ajuster)
                $shortage = $requestedQuantity - $availableStock;
                $stockIssues[] = [
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'variant_info' => $this->getVariantInfo($item->productVariant),
                    'requested_quantity' => $requestedQuantity,
                    'available_stock' => $availableStock,
                    'shortage' => $shortage,
                    'status' => 'partial_stock'
                ];
                $hasStockIssues = true;

                $updatedItems[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->product->name ?? 'Produit inconnu',
                    'original_quantity' => $requestedQuantity,
                    'new_quantity' => $availableStock,
                    'reason' => "Stock insuffisant (manque {$shortage} unité(s))"
                ];
            }
        }

        $message = '';
        if ($hasCriticalStockIssues) {
            $message = 'Produits en rupture de stock : ' . implode(', ', $criticalProducts);
        } elseif ($hasStockIssues) {
            $message = 'Ajustements de quantité nécessaires pour certains produits';
        }

        return [
            'has_stock_issues' => $hasStockIssues,
            'has_critical_stock_issues' => $hasCriticalStockIssues,
            'stock_issues' => $stockIssues,
            'updated_items' => $updatedItems,
            'message' => $message
        ];
    }

    /**
     * Appliquer les ajustements de stock pour une commande
     */
    private function applyStockAdjustments(Order $order, array $stockValidation): void
    {
        // Recharger les items avec leurs relations pour s'assurer d'avoir les données à jour
        $order->load(['items.productVariant', 'items.product']);

        if (!$stockValidation['has_stock_issues']) {
            // Pas de problèmes de stock, juste décrementer normalement
            foreach ($order->items as $item) {
                if (!$item->productVariant) {
                    continue;
                }

                $requestedQuantity = $item->quantity;
                $availableStock = $item->productVariant->stock_quantity;

                if ($availableStock >= $requestedQuantity) {
                    // Stock suffisant - soustraire la quantité commandée
                    $newStock = $availableStock - $requestedQuantity;
                    $item->productVariant->update(['stock_quantity' => $newStock]);

                    Log::info('Stock décrémenté normalement', [
                        'product_variant_id' => $item->productVariant->id,
                        'product_name' => $item->product->name ?? 'Inconnu',
                        'requested_quantity' => $requestedQuantity,
                        'old_stock' => $availableStock,
                        'new_stock' => $newStock
                    ]);
                }
            }
            return;
        }

        // Appliquer les ajustements basés sur la validation
        foreach ($stockValidation['updated_items'] as $updatedItem) {
            $item = $order->items->find($updatedItem['item_id']);
            if (!$item || !$item->productVariant) {
                continue;
            }

            $newQuantity = $updatedItem['new_quantity'];

            // Mettre à jour la quantité de l'item
            $item->update(['quantity' => $newQuantity]);

            // Mettre à jour le stock de la variante
            if ($newQuantity > 0) {
                // Stock partiel - mettre le stock à 0
                $item->productVariant->update(['stock_quantity' => 0]);

                Log::info('Stock ajusté (partiel)', [
                    'product_variant_id' => $item->productVariant->id,
                    'product_name' => $item->product->name ?? 'Inconnu',
                    'original_quantity' => $updatedItem['original_quantity'],
                    'new_quantity' => $newQuantity,
                    'stock_set_to' => 0
                ]);
            }
            // Si newQuantity = 0, le stock reste à 0 (déjà en rupture)
        }

        // Pour les items sans problème de stock, décrementer normalement
        foreach ($order->items as $item) {
            if (!$item->productVariant) {
                continue;
            }

            // Vérifier si cet item a déjà été traité dans les ajustements
            $alreadyProcessed = collect($stockValidation['updated_items'])
                ->contains('item_id', $item->id);

            if (!$alreadyProcessed) {
                $requestedQuantity = $item->quantity;
                $availableStock = $item->productVariant->stock_quantity;

                if ($availableStock >= $requestedQuantity) {
                    // Stock suffisant - soustraire la quantité commandée
                    $newStock = $availableStock - $requestedQuantity;
                    $item->productVariant->update(['stock_quantity' => $newStock]);

                    Log::info('Stock décrémenté (items sans problème)', [
                        'product_variant_id' => $item->productVariant->id,
                        'product_name' => $item->product->name ?? 'Inconnu',
                        'requested_quantity' => $requestedQuantity,
                        'old_stock' => $availableStock,
                        'new_stock' => $newStock
                    ]);
                }
            }
        }
    }

    /**
     * Annuler une commande avec raison et notification email
     */
    public function cancelOrderWithReason(int $orderId, int $vendorId, string $reason): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if (!in_array($order->status, ['pending_validation', 'in_preparation'])) {
            throw new \InvalidArgumentException('Seules les commandes en attente de validation ou en préparation peuvent être annulées avec cette méthode');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // Log avant l'update
        Log::info('Tentative d\'update de la commande', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'reason' => $reason
        ]);

        // Mettre à jour la commande
        $updated = $order->update([
            'status' => 'canceled',
            'cancelled_at' => now(),
            'cancelled_by' => 'vendor', // Utiliser 'vendor' comme défini dans l'enum de la migration
            'cancelletion_reason' => $reason,
            'cancelletion_reunded' => true,
        ]);

        // Log après l'update
        Log::info('Résultat de l\'update', [
            'order_id' => $order->id,
            'updated' => $updated,
            'new_status' => $order->fresh()->status
        ]);

        // Envoyer l'email de notification au client
        try {
            $this->sendCancellationEmail($order, $reason, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email d\'annulation', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            // On continue même si l'email échoue
        }

        return [
            'success' => true,
            'message' => 'Commande annulée avec succès. Un email a été envoyé au client.',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'cancelled_at' => $order->cancelled_at,
                'cancelled_by' => $order->cancelled_by,
                'reason' => $order->cancelletion_reason,
            ],
        ];
    }

    /**
     * Notifier le client (pour les commandes abandonnées)
     */
    public function notifyCustomer(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if (!in_array($order->status, ['to_be_collected', 'abandoned'])) {
            throw new \InvalidArgumentException('Cette action n\'est disponible que pour les commandes à retirer ou abandonnées');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // S'assurer que les relations sont chargées
        $order->load('user');

        // Log avant l'envoi
        Log::info('Tentative d\'envoi email de rappel au client', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'vendor_id' => $vendorId
        ]);

        // Envoyer l'email de rappel au client
        try {
            $this->sendCustomerReminderEmail($order, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email de rappel', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw new \InvalidArgumentException('Impossible d\'envoyer l\'email de rappel. Erreur: ' . $e->getMessage());
        }

        Log::info('Email de rappel envoyé avec succès', [
            'order_id' => $order->id
        ]);

        return [
            'success' => true,
            'message' => 'Email de rappel envoyé au client avec succès',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'reminder_sent' => true,
            ],
        ];
    }

    /**
     * Trouve une commande par ID et vendeur
     */
    private function findOrderByVendor(int $orderId, int $vendorId): Order
    {
        $order = Order::where('id', $orderId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (!$order) {
            throw new \InvalidArgumentException('Commande introuvable');
        }

        return $order;
    }

    /**
     * Récupère les orders avec pagination
     *
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPaginated(int $perPage = 20, array $filters = [])
    {
        return Order::select(
                'id',
                'order_number',
                'user_id',
                'status',
                'payment_method',
                'payment_status',
                'total_amount',
                'created_at'
            )
            ->status($filters['status'] ?? null)
            ->paymentStatus($filters['payment_status'] ?? null)
            ->paymentMethod($filters['payment_method'] ?? null)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Récupère une commande par son ID
     *
     * @param int $id
     * @return Order
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findById(int $id): Order
    {
        return Order::with([
            'user:id,first_name,last_name,email,phone',  // <<=== récupérer uniquement les champs nécessaires
            'items.product',
            'items.productVariant'
        ])
            ->findOrFail($id);
    }
    /*
     * Retourne la liste des commandes pour un client.
    */
    public function listClientOrders(User $user, array $filters = []): Collection
    {
        $query = $user->orders()
            ->with(['items.product', 'items.productVariant'])
            ->latest('created_at');

        // Filtrer par statut si spécifié
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtrer par méthode de paiement si spécifiée
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        return $query->get()->map(function (Order $order) {
            Log::info("INFO DE LA COMMANDE: ", array($order));
            return $this->formatOrderForList($order);
        });
    }

    /**
     * Retourne les détails complets d'une commande.
     */
    public function getClientOrderDetails(Order $order): array
    {
        $order->load([
            'items.product.images',
            'items.product.vendor',
            'items.productVariant',
            'user'
        ]);

        return $this->formatOrderDetails($order);
    }

    /**
     * Crée une nouvelle commande.
     */
    public function CreateOrder(User $user, array $data): array
    {

        return DB::transaction(function () use ($user, $data) {
            $ordersPayload = [];

            // --- 1) si on reçoit des "orders" groupés depuis le front
            if (!empty($data['orders']) && is_array($data['orders'])) {
                foreach ($data['orders'] as $idx =>$ord) {
                    // s'assurer que items est un array non vide
                    if (empty($ord['items']) || !is_array($ord['items'])) {
                        throw new Exception('Chaque commande doit contenir au moins un article.');
                    }

                    // Valider & préparer (unit_price, vendor_id, etc.) les items reçus pour cette commande
                    $preparedItems = $this->validateAndPrepareItems($ord['items']);

                    // Vérifier que tous les items appartiennent au même vendor
                    $vendorsInItems = collect($preparedItems)->pluck('vendor_id')->unique();
                    if ($vendorsInItems->count() > 1) {
                        throw new Exception('Les articles d\'une même commande doivent appartenir au même vendeur.');
                    }

                    $vendorIdFromItems = $vendorsInItems->first();
                    $vendorId = $ord['vendor_id'] ?? $vendorIdFromItems;

                    if (empty($vendorId)) {
                        throw new Exception('Vendor inconnu pour une des commandes.');
                    }

                    $payload = [
                        'vendor_id' => $vendorId,
                        'payment_method' => $ord['payment_method'] ?? ($data['payment_method'] ?? null),
                        'promo_code_id' => $ord['promo_code_id'] ?? null,
                        'promo_code' => $ord['promo_code'] ?? null,
                        'items' => $preparedItems,
                    ];
                    $ordersPayload[] = $payload;
                }
            } else {
                // --- 2) sinon on reçoit des "items" plats -> valider & grouper par vendor
                if (empty($data['items']) || !is_array($data['items'])) {
                    throw new Exception('Aucun item dans la commande.');
                }

                $prepared = $this->validateAndPrepareItems($data['items']);
                $grouped = collect($prepared)->groupBy('vendor_id');

                foreach ($grouped as $vendorId => $itemsForVendor) {
                    $ordersPayload[] = [
                        'vendor_id' => $vendorId,
                        'payment_method' => $data['payment_method'] ?? null,
                        'promo_code_id' => $data['promo_code_id'] ?? null,
                        'promo_code' => $data['promo_code'] ?? null,
                        'items' => $itemsForVendor->values()->all(),
                    ];
                }
            }

            // --- 3) Création des commandes
            $createdOrders = [];

            foreach ($ordersPayload as $idx => $ordPayload) {
                if (empty($ordPayload['vendor_id'])) {
                    throw new Exception('Vendor inconnu pour un des articles.');
                }

                // calculer total à partir des unit_price fournis par validateAndPrepareItems
                $totalAmount = 0;
                $discountAmount = 0;
                $promoCode = null;

                // ✅ Calculer le sous-total avec les prix fournis par le front
                foreach ($ordPayload['items'] as $itemIdx => $it) {
                    $qty = (int)$it['quantity'];
                    $unitPrice = (float)$it['unit_price'];
                    $itemTotal = $unitPrice * $qty;
                    $totalAmount += $itemTotal;
                }

                // ✅ Si un code promo est fourni
                if (!empty($ordPayload['promo_code_id'])) {
                    $promoCode = PromoCode::find($ordPayload['promo_code_id']);

                    if (!$promoCode) {
                        Log::warning("⚠️ Code promo ID {$ordPayload['promo_code_id']} introuvable en BDD");
                    } elseif ($promoCode->vendor_id != $ordPayload['vendor_id']) {
                        Log::warning("⚠️ Code promo vendor_id={$promoCode->vendor_id} != order vendor_id={$ordPayload['vendor_id']}");
                        $promoCode = null;
                    } else {
                        Log::info("✅ Code promo trouvé en BDD:", [
                            'id' => $promoCode->id,
                            'code' => $promoCode->code,
                            'type' => $promoCode->type,
                            'value' => $promoCode->value,
                            'vendor_id' => $promoCode->vendor_id,
                            'status' => $promoCode->status,
                            'used_count' => $promoCode->used_count,
                            'max_uses' => $promoCode->max_uses,
                        ]);

                        // ✅ Vérifications temporelles et d'usage
                        $now = Carbon::now();

                        if ($promoCode->valid_from && Carbon::parse($promoCode->valid_from)->isFuture()) {
                            throw new Exception("Le code promo {$promoCode->code} n'est pas encore actif");
                        }

                        if ($promoCode->valid_until && Carbon::parse($promoCode->valid_until)->isPast()) {
                            throw new Exception("Le code promo {$promoCode->code} a expiré");
                        }

                        if ($promoCode->max_uses && $promoCode->used_count >= $promoCode->max_uses) {
                            throw new Exception("Le code promo {$promoCode->code} a atteint sa limite d'utilisation");
                        }

                        // ✅ Vérifier montant minimum
                        if ($promoCode->min_order_amount && $totalAmount < $promoCode->min_order_amount) {
                            throw new Exception("Montant minimum de {$promoCode->min_order_amount} CFA requis pour ce code promo");
                        }

                        // ✅ Calculer la réduction sur les produits éligibles
                        $eligibleProductIds = $promoCode->products()->pluck('products.id')->toArray();

                        foreach ($ordPayload['items'] as $it) {
                            if (in_array($it['product_id'], $eligibleProductIds)) {
                                $itemTotal = (float)$it['unit_price'] * (int)$it['quantity'];

                                if ($promoCode->type === 'percentage') {
                                    $itemDiscount = ($itemTotal * $promoCode->value) / 100;
                                } else {
                                    $itemDiscount = min($promoCode->value, $itemTotal);
                                }

                                $discountAmount += $itemDiscount;
                            }
                        }

                        if ($discountAmount > 0) {
                            // ✅ Incrémenter le compteur d'utilisation
                            $promoCode->increment('used_count');
                        } else {
                            $promoCode = null;
                        }
                    }
                }

                $finalAmount = max(0, $totalAmount - $discountAmount);

                $orderData = [
                    'order_number' => $this->generateOrderNumber(),
                    'user_id' => $user->id,
                    'vendor_id' => $ordPayload['vendor_id'],
                    'promo_code_id' => $promoCode ? $promoCode->id : null,
                    'subtotal' => round($totalAmount, 2),
                    'discount_amount' => round($discountAmount, 2),
                    'total_amount' => round($finalAmount, 2),
                    'status' => 'pending_validation',
                    'payment_method' => $ordPayload['payment_method'] ?? null,
                    'payment_status' => 'pending',
                ];

                // ✅ Créer la commande avec toutes les infos
                $order = Order::create($orderData);

                // ✅ Créer les items
                foreach ($ordPayload['items'] as $itemIdx => $it) {
                    $orderItemData = [
                        'order_id' => $order->id,
                        'product_id' => $it['product_id'],
                        'product_variant_id' => $it['product_variant_id'] ?? null,
                        'vendor_id' => $ordPayload['vendor_id'],
                        'quantity' => (int)$it['quantity'],
                        'unit_price' => (float)$it['unit_price'],
                    ];
                    OrderItem::create($orderItemData);
                }

                $order->load([
                    'items.product.images',
                    'items.product.vendor',
                    'items.productVariant',
                    'vendor',
                    'promoCode'
                ]);

                $formattedOrder = $this->formatOrderDetails($order);
                
                $createdOrders[] = $formattedOrder;
            }

            return $createdOrders;
        });
    }

    /**
     * Annule une commande.
     */
    public function clientCancelOrder(Order $order): array
    {
        // Statuts qu'on autorise explicitement à annuler côté client
        $allowedStatuses = ['pending_validation', 'in_preparation', 'to_be_collected'];

        Log::info('clientCancelOrder', ['order_id' => $order->id, 'status' => $order->status]);
        if (!in_array($order->status, $allowedStatuses, true)) {
            throw new Exception('Cette commande ne peut pas être annulée.');
        }

        if ($order->status === 'canceled') {
            throw new Exception('Cette commande a déjà été annulée.');
        }

        // Mise à jour minimale demandée : status + meta d'annulation côté client
        $order->update([
            'status' => 'canceled',
            'cancelled_at' => now(),
            'cancelled_by' => 'customer', // on laisse la raison pour le vendeur
        ]);

        return $this->formatOrderDetails($order);
    }

    /**
     * Marque une commande comme collectée.
     */
    public function clientMarkAsCollected(Order $order): array
    {
        // Vérifier que la commande peut être collectée
        if (!in_array($order->status, ['to_be_collected', 'abandoned'])) {
            throw new Exception('Seules les commandes à retirer ou abandonnées peuvent être collectées.');
        }

        $order->update([
            'status' => 'collected',
        ]);

        return $this->formatOrderDetails($order);
    }

    /**
     * Formate une commande pour la liste.
     */
    private function formatOrderForList(Order $order): array
    {
        $items = $order->items->map(function ($item) {
            // Nom du produit (avec infos variante si existante)
            $productName = $item->product->name ?? 'Produit inconnu';

            if ($item->productVariant) {
                // concatène des details de variante si disponibles (taille / couleur)
                $parts = [];
                if (!empty($item->productVariant->size)) {
                    $parts[] = 'Taille: ' . $item->productVariant->size;
                }
                if (!empty($item->productVariant->color)) {
                    $parts[] = 'Couleur: ' . $item->productVariant->color;
                }
                if (!empty($parts)) {
                    $productName .= ' (' . implode(' - ', $parts) . ')';
                }
            }

            $quantity = isset($item->quantity) ? (int) $item->quantity : 0;
            $unitPrice = isset($item->unit_price) ? (float) $item->unit_price : 0.0;
            $itemTotal = round($unitPrice * $quantity, 2);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $productName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'item_total' => $itemTotal,
            ];
        })->toArray();

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'total_amount' => $order->total_amount,
            'items_count' => $order->items->count(),
            'items' => $items,
            "seller_pickup_at" => $order->seller_pickup_at,
            "actual_pickup_at" => $order->actual_pickup_at,
            "cancelletion_reason" => $order->cancelletion_reason,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }

    /**
     * Formate les détails complets d'une commande.
     */
    private function formatOrderDetails(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal' => (float)$order->subtotal,
            'discount_amount' => (float)$order->discount_amount,
            'total_amount' => (float)$order->total_amount,
            'promo_code' => $order->promoCode ? [
                'id' => $order->promoCode->id,
                'code' => $order->promoCode->code,
                'name' => $order->promoCode->name,
            ] : null,
            'vendor' => $order->vendor ? [
                'id' => $order->vendor->id,
                'name' => $order->vendor->name_store ?? $order->vendor->name ?? null,
                'address' => [
                    'street' => $order->vendor->user->street,
                    'city' => $order->vendor->user->city,
                    'postal_code' => $order->vendor->user->postal_code,
                ]
            ] : null,
            'items' => $order->items->map(function (OrderItem $item) {
                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'description' => $item->product->description,
                        'images' => $item->product->images,
                        'vendor' => [
                            'id' => $item->product->vendor->id,
                            'name' => $item->product->vendor->name ?? $item->product->vendor->name_store,
                        ],
                    ],
                    'product_variant' => $item->productVariant ? [
                        'id' => $item->productVariant->id,
                        'name' => $item->productVariant->name,
                        'price' => $item->productVariant->price,
                    ] : null,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->unit_price * $item->quantity,
                ];
            }),
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
        ];
    }


    /**
     * Valide et prépare les articles de la commande.
     */
    private function validateAndPrepareItems(array $items): array
    {
        $preparedItems = [];

        foreach ($items as $idx => $item) {
            // normalisation minimale
            $productId = $item['product_id'] ?? $item['productId'] ?? null;
            $variantId = $item['product_variant_id'] ?? $item['productVariantId'] ?? $item['variant_id'] ?? null;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : null;

            if (empty($productId)) {
                throw new Exception('product_id manquant pour un article.');
            }

            $product = Product::with('vendor')->find($productId);
            if (!$product) {
                throw new Exception("Produit avec l'ID {$productId} introuvable.");
            }

            $variant = null;
            if (!empty($variantId)) {
                $variant = ProductVariant::where('product_id', $product->id)->find($variantId);
                if (!$variant) {
                    throw new Exception("Variante {$variantId} introuvable pour le produit {$product->id}.");
                }
            }

            // ✅ Si le front n'a pas envoyé de prix, utiliser celui de la BDD
            if ($unitPrice === null) {
                $unitPrice = $variant ? (float)$variant->price : (float)$product->price;
            }

            // vendor_id depuis product relation
            $vendorId = $product->vendor_id ?? ($product->vendor->id ?? null);
            if (empty($vendorId)) {
                throw new Exception("Produit {$product->id} sans vendeur associé.");
            }

            $preparedItem = [
                'product_id' => (int)$product->id,
                'product_variant_id' => $variant ? (int)$variant->id : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'vendor_id' => (int)$vendorId,
            ];

            $preparedItems[] = $preparedItem;
        }

        return $preparedItems;
    }


    /**
     * Calcule le montant total de la commande.
     */
    private function calculateTotalAmount(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['unit_price'] * $item['quantity'];
        }
        return $total;
    }

    /**
     * Génère un numéro de commande unique.
     */
    private function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'FAET-' . strtoupper(Str::random(8));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }


    /**
     * Valider une commande avec date de retrait et notification email
     */
    public function validateOrderWithPickupDate(int $orderId, int $vendorId, string $pickupDate): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'pending_validation') {
            throw new \InvalidArgumentException('Seules les commandes en attente de validation peuvent être validées avec cette méthode');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // VÉRIFICATION DU STOCK AVANT LA VALIDATION
        $stockValidation = $this->validateStockForOrder($order);

        // Si il y a des problèmes de stock critiques (rupture totale), empêcher la validation
        if ($stockValidation['has_critical_stock_issues']) {
            throw new \InvalidArgumentException('Impossible de valider la commande : rupture de stock pour certains produits. ' . $stockValidation['message']);
        }

        // Log avant l'envoi de l'email
        Log::info('Tentative d\'envoi email de validation', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'pickup_date' => $pickupDate,
            'stock_issues' => $stockValidation['stock_issues'] ?? []
        ]);

        // Envoyer l'email de notification au client AVANT la mise à jour
        try {
            $this->sendValidationEmail($order, $pickupDate, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email de validation', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw new \InvalidArgumentException('Impossible d\'envoyer l\'email de notification. La commande n\'a pas été validée.');
        }

        // SEULEMENT SI l'email est envoyé avec succès, mettre à jour la commande et le stock
        Log::info('Email envoyé avec succès, mise à jour de la commande et du stock', [
            'order_id' => $order->id
        ]);

        // Appliquer les ajustements de stock
        $this->applyStockAdjustments($order, $stockValidation);

        $updated = $order->update([
            'status' => 'in_preparation',
            'actual_pickup_at' => Carbon::parse($pickupDate)->format('Y-m-d H:i:s'),
        ]);

        // Log après l'update
        Log::info('Résultat de l\'update de validation', [
            'order_id' => $order->id,
            'updated' => $updated,
            'new_status' => $order->fresh()->status,
            'pickup_date' => $order->fresh()->actual_pickup_at
        ]);

        // Préparer la réponse
        $response = [
            'success' => true,
            'message' => 'Commande validée avec succès. Un email de confirmation avec la date de retrait a été envoyé au client.',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'actual_pickup_at' => $order->actual_pickup_at,
                'pickup_date_formatted' => Carbon::parse($pickupDate)->format('d/m/Y'),
            ],
        ];

        // Ajouter les informations sur les ajustements de stock si nécessaire
        if ($stockValidation['has_stock_issues']) {
            $response['stock_issues'] = $stockValidation['stock_issues'];
            $response['updated_items'] = $stockValidation['updated_items'];
            $response['message'] .= ' Des ajustements de quantité ont été effectués en fonction du stock disponible.';
        }

        return $response;
    }

    /**
     * Envoie un email de notification de validation au client
     */
    private function sendValidationEmail(Order $order, string $pickupDate, $vendor): void
    {
        Log::info('Début envoi notification de validation', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A',
            'pickup_date' => $pickupDate
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'order_amount' => $order->total_amount,
            'pickup_date' => $pickupDate,
            'pickup_date_formatted' => Carbon::parse($pickupDate)->format('d/m/Y'),
            'validation_date' => now()->format('d/m/Y à H:i'),
            'shop_name' => $shopName,
            'name_store' => $shopName, // Ajout pour le template
        ];

        try {
            // Envoyer la notification de validation
            $customer->notify(new OrderValidationNotification($emailData));

            // Log pour traçabilité
            Log::info('Notification de validation envoyée avec succès', [
                'to' => $customer->email,
                'order_number' => $emailData['order_number'],
                'pickup_date' => $emailData['pickup_date_formatted'],
                'shop_name' => $emailData['shop_name']
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de la notification de validation', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'customer_email' => $customer->email
            ]);
            throw $e;
        }
    }

    /**
     * Marquer une commande comme prête et notifier le client
     */
    public function notifyOrderReady(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if ($order->status !== 'in_preparation') {
            throw new \InvalidArgumentException('Seules les commandes en préparation peuvent être marquées comme prêtes');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // S'assurer que les relations sont chargées
        $order->load('user');

        // Log avant les opérations
        Log::info('Tentative de marquer commande comme prête et envoyer email', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'vendor_address' => [
                'rue' => $vendor->rue,
                'code_postal' => $vendor->code_postal,
                'ville' => $vendor->ville,
            ]
        ]);

        // 1. D'ABORD : Changer le statut de la commande
        $updated = $order->update([
            'status' => 'to_be_collected'
        ]);

        Log::info('Statut de la commande mis à jour', [
            'order_id' => $order->id,
            'updated' => $updated,
            'new_status' => $order->fresh()->status
        ]);

        // 2. ENSUITE : Envoyer l'email de notification au client
        try {
            $this->sendOrderReadyEmail($order, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email commande prête', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            // On continue même si l'email échoue (comme pour la validation)
        }

        Log::info('Commande marquée comme prête et email envoyé', [
            'order_id' => $order->id,
            'final_status' => $order->status
        ]);

        return [
            'success' => true,
            'message' => 'Commande marquée comme prête avec succès. Le client a été notifié par email.',
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status, // Nouveau statut : "to_be_collected"
                'notification_sent' => true,
            ],
        ];
    }

    /**
     * Envoie un email de notification commande prête au client
     */
    private function sendOrderReadyEmail(Order $order, $vendor): void
    {
        Log::info('Début envoi notification commande prête', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A'
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'order_amount' => $order->total_amount,
            'notification_date' => now()->format('d/m/Y à H:i'),
            'name_store' => $shopName,
            'shop_address_street' => $vendor->rue ?? 'Adresse non renseignée',
            'shop_address_postal_code' => $vendor->code_postal ?? '',
            'shop_address_city' => $vendor->ville ?? 'Ville non renseignée',
            'shop_address_country' => 'France',
        ];

        // Envoyer la notification commande prête (même structure que validation)
        $customer->notify(new OrderReadyNotification($emailData));

        // Log pour traçabilité
        Log::info('Notification commande prête envoyée avec succès', [
            'to' => $customer->email,
            'order_number' => $emailData['order_number'],
            'name_store' => $emailData['name_store']
        ]);
    }

    /**
     * Marquer une commande comme récupérée et notifier le client
     */
    public function markAsPickedUp(int $orderId, int $vendorId): array
    {
        $order = $this->findOrderByVendor($orderId, $vendorId);

        if (!in_array($order->status, ['to_be_collected', 'abandoned'])) {
            throw new \InvalidArgumentException('Seules les commandes à retirer ou abandonnées peuvent être marquées comme récupérées');
        }

        // Récupérer les informations du vendeur pour l'email
        $vendor = $order->vendor;
        if (!$vendor) {
            throw new \InvalidArgumentException('Vendeur introuvable');
        }

        // S'assurer que les relations sont chargées
        $order->load('user');

        // Log avant les opérations
        Log::info('Tentative de marquer commande comme récupérée et envoyer email', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'vendor_id' => $vendorId
        ]);

        // 1. ENVOYER L'EMAIL D'ABORD
        try {
            $this->sendPickupConfirmationEmail($order, $vendor);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email de confirmation de retrait', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw new \InvalidArgumentException('Impossible d\'envoyer l\'email de confirmation. La commande n\'a pas été marquée comme récupérée. Erreur: ' . $e->getMessage());
        }

        // 2. SI EMAIL RÉUSSI : Mettre à jour SEULEMENT status et actual_pickup_at
        $pickupDate = now()->format('Y-m-d'); // Date uniquement, pas l'heure

        Log::info('Tentative de mise à jour de la base de données', [
            'order_id' => $order->id,
            'current_status' => $order->status,
            'pickup_date' => $pickupDate
        ]);

        $updated = $order->update([
            'status' => 'collected',
            'actual_pickup_at' => $pickupDate
        ]);

        Log::info('Commande marquée comme récupérée avec succès', [
            'order_id' => $order->id,
            'updated' => $updated,
            'new_status' => $order->fresh()->status,
            'actual_pickup_at' => $pickupDate
        ]);

        return [
            'success' => true,
            'message' => 'Commande marquée comme récupérée et client notifié avec succès',
            'data' => [
                'id' => $order->id,
                'status' => 'collected',
                'actual_pickup_at' => $pickupDate,
                'pickup_confirmed' => true,
            ],
        ];
    }

    /**
     * Envoie un email de confirmation de retrait au client
     */
    private function sendPickupConfirmationEmail(Order $order, $vendor): void
    {
        Log::info('Début envoi confirmation de retrait', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A'
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'order_amount' => $order->total_amount,
            'pickup_date' => now()->format('d/m/Y'),
            'pickup_time' => now()->format('H:i'),
            'name_store' => $shopName,
        ];

        // Envoyer la notification de confirmation de retrait
        $customer->notify(new OrderPickedUpNotification($emailData));

        // Log pour traçabilité
        Log::info('Confirmation de retrait envoyée avec succès', [
            'to' => $customer->email,
            'order_number' => $emailData['order_number'],
            'name_store' => $emailData['name_store']
        ]);
    }

    /**
     * Envoie un email de notification d'annulation au client
     */
    private function sendCancellationEmail(Order $order, string $reason, $vendor): void
    {
        Log::info('Début envoi notification', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A'
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'order_amount' => $order->total_amount,
            'cancellation_reason' => $reason,
            'cancellation_date' => now()->format('d/m/Y à H:i'),
            'name_store' => $shopName,
        ];

        try {
            // Envoyer la notification d'annulation
            $customer->notify(new OrderCancellationNotification($emailData));

            // Log pour traçabilité
            Log::info('Notification d\'annulation envoyée avec succès', [
                'to' => $customer->email,
                'order_number' => $emailData['order_number'],
                'name_store' => $emailData['name_store']
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de la notification', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'customer_email' => $customer->email
            ]);
            throw $e;
        }
    }

    /**
     * Envoie un email de rappel au client pour une commande prête
     */
    private function sendCustomerReminderEmail(Order $order, $vendor): void
    {
        Log::info('Début envoi rappel au client', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A',
            'seller_pickup_at' => $order->seller_pickup_at
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        // Calculer les jours d'attente depuis que la commande est prête
        $pickupReadyDate = null;
        $daysWaiting = 0;

        if ($order->seller_pickup_at) {
            $pickupReadyDate = Carbon::parse($order->seller_pickup_at);
            $daysWaiting = $pickupReadyDate->diffInDays(now());
            $pickupReadyDateFormatted = $pickupReadyDate->format('d/m/Y');
        } else {
            // Fallback si seller_pickup_at n'est pas défini
            $pickupReadyDateFormatted = 'Date non disponible';
            Log::warning('seller_pickup_at non défini pour la commande', ['order_id' => $order->id]);
        }

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'order_amount' => $order->total_amount,
            'pickup_ready_date' => $pickupReadyDateFormatted,
            'days_waiting' => $daysWaiting,
            'reminder_date' => now()->format('d/m/Y à H:i'),
            'name_store' => $shopName,
            'shop_address_street' => $vendor->rue ?? 'Adresse non renseignée',
            'shop_address_postal_code' => $vendor->code_postal ?? '',
            'shop_address_city' => $vendor->ville ?? 'Ville non renseignée',
            'shop_address_country' => 'France',
        ];

        // Utiliser la nouvelle notification de rappel
        $customer->notify(new OrderReminderNotification($emailData));

        // Log pour traçabilité
        Log::info('Rappel client envoyé avec succès', [
            'to' => $customer->email,
            'order_number' => $emailData['order_number'],
            'name_store' => $emailData['name_store'],
            'days_waiting' => $daysWaiting,
            'pickup_ready_date' => $pickupReadyDateFormatted
        ]);
    }

    /**
     * Envoie un email de confirmation de remboursement au client
     */
    private function sendRefundConfirmationEmail(Order $order, $vendor): void
    {
        Log::info('Début envoi confirmation de remboursement', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'has_user' => !is_null($order->user),
            'user_email' => $order->user->email ?? 'N/A'
        ]);

        if (!$order->user || !$order->user->email) {
            throw new \Exception('Impossible d\'envoyer l\'email : client ou email introuvable');
        }

        $customer = $order->user;
        $shopName = $vendor->name_store ?? 'Notre boutique';

        $emailData = [
            'customer_name' => $customer->first_name . ' ' . $customer->last_name,
            'order_number' => $order->order_number,
            'refund_amount' => $order->total_amount,
            'refund_date' => now()->format('d/m/Y à H:i'),
            'name_store' => $shopName,
        ];

        // Envoyer la notification de remboursement
        $customer->notify(new OrderRefundNotification($emailData));

        // Log pour traçabilité
        Log::info('Confirmation de remboursement envoyée avec succès', [
            'to' => $customer->email,
            'order_number' => $emailData['order_number'],
            'refund_amount' => $emailData['refund_amount'],
            'name_store' => $emailData['name_store']
        ]);
    }

    /**
     * Récupérer les transactions pour l'export
     */
    public function getTransactionsForExport($vendorId, $filters = [])
    {
        try {
            $query = Order::where('vendor_id', $vendorId)
                ->whereIn('status', ['collected', 'refunded'])
                ->with(['customer', 'items.product', 'items.productVariant']);

            // Appliquer les filtres de période et date
            [$start, $end] = $this->resolveDateRange(
                $filters['period'] ?? null,
                $filters['start_date'] ?? null,
                $filters['end_date'] ?? null
            );
            
            if ($start && $end) {
                $query->whereBetween('created_at', [$start, $end]);
            }

            // Appliquer le filtre de recherche
            if (isset($filters['search']) && $filters['search']) {
                $searchTerm = $filters['search'];
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('order_number', 'like', "%{$searchTerm}%")
                      ->orWhereHas('customer', function ($customerQuery) use ($searchTerm) {
                          $customerQuery->where('name', 'like', "%{$searchTerm}%")
                                       ->orWhere('email', 'like', "%{$searchTerm}%");
                      });
                });
            }

            // Appliquer le filtre de statut
            if (isset($filters['status']) && $filters['status'] && $filters['status'] !== 'all') {
                $query->where('status', $filters['status']);
            }

            $orders = $query->orderBy('created_at', 'desc')->get();

            // Transformer les commandes en transactions
            $transactions = collect();

            foreach ($orders as $order) {
                // Transaction de vente
                if ($order->status === 'collected') {
                    $transactions->push([
                        'order_number' => $order->order_number,
                        'date' => $order->created_at->format('d/m/Y'),
                        'type' => 'sale',
                        'customer' => [
                            'name' => $order->customer->name ?? 'N/A',
                            'email' => $order->customer->email ?? 'N/A'
                        ],
                        'amount' => $order->total_amount,
                        'status' => $order->status
                    ]);
                }

                // Transaction de remboursement
                if ($order->status === 'refunded') {
                    $transactions->push([
                        'order_number' => $order->order_number,
                        'date' => $order->updated_at->format('d/m/Y'),
                        'type' => 'refund',
                        'customer' => [
                            'name' => $order->customer->name ?? 'N/A',
                            'email' => $order->customer->email ?? 'N/A'
                        ],
                        'amount' => $order->total_amount,
                        'status' => $order->status
                    ]);
                }
            }

            // Appliquer le filtre de type si spécifié
            if (isset($filters['type']) && $filters['type'] && $filters['type'] !== 'all') {
                $transactions = $transactions->filter(function ($transaction) use ($filters) {
                    return $transaction['type'] === $filters['type'];
                });
            }

            return $transactions;

        } catch (\Exception $e) {
            Log::error('Erreur récupération transactions pour export: ' . $e->getMessage());
            return collect();
        }
    }
}