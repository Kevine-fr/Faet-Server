<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Customer;
use Tymon\JWTAuth\Facades\JWTAuth;

class DashboardController extends Controller
{
    /**
     * Récupérer toutes les données du dashboard
     */
    public function index(Request $request)
    {
        try {
            $user = JWTAuth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $period = $request->get('period', 'today');

            // Récupérer les statistiques
            $stats = $this->calculateStats($user, $period);
            
            // Récupérer l'état des commandes
            $orderStatus = $this->calculateOrderStatus($user, $period);
            
            // Récupérer les commandes récentes
            $recentOrders = $this->getRecentOrdersData($user, $period);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'orderStatus' => $orderStatus,
                    'recentOrders' => $recentOrders
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données du dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les statistiques du dashboard
     */
    public function getStats(Request $request)
    {
        try {
            $user = JWTAuth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $period = $request->get('period', 'today');
            $stats = $this->calculateStats($user, $period);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer l'état des commandes
     */
    public function getOrderStatus(Request $request)
    {
        try {
            $user = JWTAuth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $period = $request->get('period', 'today');
            $orderStatus = $this->calculateOrderStatus($user, $period);

            return response()->json([
                'success' => true,
                'data' => $orderStatus
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'état des commandes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les commandes récentes
     */
    public function getRecentOrders(Request $request)
    {
        try {
            $user = JWTAuth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $vendor = $user->vendor;
            if (!$vendor) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'data' => [],
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => (int) $request->get('per_page', 5),
                        'total' => 0,
                    ],
                ]);
            }

            $perPage = (int) $request->get('per_page', 5);
            if ($perPage <= 0) { $perPage = 10; }
            $page = (int) $request->get('page', 1);
            if ($page <= 0) { $page = 1; }
            $status = $request->get('status');
            $allowedStatuses = ['in_preparation', 'to_be_collected', 'collected', 'canceled', 'abandoned'];
            if ($status && !in_array($status, $allowedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statut invalide',
                ], 422);
            }
            $period = $request->get('period', 'today');
            $start = match ($period) {
                'week' => Carbon::now()->startOfWeek(),
                'month' => Carbon::now()->startOfMonth(),
                default => Carbon::today(),
            };
            $end = Carbon::now();

            $query = Order::with(['user:id,first_name,last_name,email'])
                ->where('vendor_id', $vendor->id)
                ->whereBetween('created_at', [$start, $end])
                ->when($status, function ($q) use ($status) {
                    $q->where('status', $status);
                })
                ->orderBy('created_at', 'desc');

            $paginator = $query
                ->paginate($perPage, ['*'], 'page', $page)
                ->through(function ($order) {
                    return [
                        'id' => $order->id,
                        'orderNumber' => $order->id,
                        'createdAt' => $order->created_at->toISOString(),
                        'status' => $order->status,
                        'amount' => $order->total_amount,
                        'customer' => [
                            'name' => $order->user ?
                                ($order->user->first_name . ' ' . $order->user->last_name) :
                                'Client inconnu',
                            'email' => $order->user ? $order->user->email : 'N/A',
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $paginator,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des commandes récentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculer les statistiques
     */
    private function calculateStats($user, string $period = 'today')
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $start = match ($period) {
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => Carbon::today(),
        };
        $end = Carbon::now();

        // Récupérer le vendor de l'utilisateur
        $vendor = $user->vendor;
        if (!$vendor) {
            return [
                'ordersToday' => ['count' => 0, 'amount' => 0, 'percentage' => 0],
                'ordersInProgress' => ['count' => 0, 'pending' => 0, 'preparing' => 0],
                'visitorsToday' => ['count' => 0, 'percentage' => 0],
                'products' => ['count' => 0, 'connectedSite' => 'Aucun vendor associé']
            ];
        }

        // Commandes selon période (filtrées par vendor)
        $ordersToday = Order::where('vendor_id', $vendor->id)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $ordersYesterday = Order::where('vendor_id', $vendor->id)
            ->whereDate('created_at', $yesterday)
            ->get();

        $ordersTodayCount = $ordersToday->count();
        $ordersYesterdayCount = $ordersYesterday->count();
        
        $ordersTodayAmount = $ordersToday->sum('total_amount');
        $ordersYesterdayAmount = $ordersYesterday->sum('total_amount');

        // Calculer le pourcentage de changement
        $percentageChange = $ordersYesterdayCount > 0 
            ? (($ordersTodayCount - $ordersYesterdayCount) / $ordersYesterdayCount) * 100 
            : 0;

        // Commandes en cours (en préparation et à retirer) sur la période
        $ordersInProgress = Order::where('vendor_id', $vendor->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', ['in_preparation', 'to_be_collected'])
            ->get();

        $pendingCount = $ordersInProgress->where('status', 'in_preparation')->count();
        $preparingCount = $ordersInProgress->where('status', 'to_be_collected')->count();

        // Visiteurs (simulation - à adapter selon vos besoins)
        $visitorsToday = rand(50, 200); // À remplacer par de vraies données
        $visitorsYesterday = rand(40, 180);
        $visitorsPercentage = $visitorsYesterday > 0 
            ? (($visitorsToday - $visitorsYesterday) / $visitorsYesterday) * 100 
            : 0;

        // Produits
        $productsCount = Product::where('vendor_id', $vendor->id)->count();
        
        // Récupérer les informations du vendeur connecté
        $vendor = $user->vendor;
        $connectedSite = 'Site non connecté';
        
        if ($vendor && $vendor->website_url) {
            $connectedSite = 'Site connecté : ' . $vendor->website_url;
        }

        return [
            'ordersToday' => [
                'count' => $ordersTodayCount,
                'amount' => $ordersTodayAmount,
                'percentage' => round($percentageChange, 1)
            ],
            'ordersInProgress' => [
                'count' => $ordersInProgress->count(),
                'pending' => $pendingCount,
                'preparing' => $preparingCount
            ],
            'visitorsToday' => [
                'count' => $visitorsToday,
                'percentage' => round($visitorsPercentage, 1)
            ],
            'products' => [
                'count' => $productsCount,
                'connectedSite' => $connectedSite
            ]
        ];
    }

    /**
     * Calculer l'état des commandes
     */
    private function calculateOrderStatus($user, string $period = 'today')
    {
        $vendor = $user->vendor;
        if (!$vendor) {
            return [
                'in_preparation' => 0,
                'to_be_collected' => 0,
                'collected' => 0,
                'canceled' => 0,
                'abandoned' => 0
            ];
        }

        $start = match ($period) {
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => Carbon::today(),
        };
        $end = Carbon::now();

        $orderStatus = Order::select('status', DB::raw('count(*) as count'))
            ->where('vendor_id', $vendor->id)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'in_preparation' => $orderStatus['in_preparation'] ?? 0,
            'to_be_collected' => $orderStatus['to_be_collected'] ?? 0,
            'collected' => $orderStatus['collected'] ?? 0,
            'canceled' => $orderStatus['canceled'] ?? 0,
            'abandoned' => $orderStatus['abandoned'] ?? 0
        ];
    }

    /**
     * Récupérer les commandes récentes
     */
    private function getRecentOrdersData($user, string $period = 'today')
    {
        $vendor = $user->vendor;
        if (!$vendor) {
            return [];
        }

        $start = match ($period) {
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            default => Carbon::today(),
        };
        $end = Carbon::now();

        $recentOrders = Order::with(['user:id,first_name,last_name,email'])
            ->where('vendor_id', $vendor->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'orderNumber' => $order->order_number, // Numéro de commande
                    'createdAt' => $order->created_at->toISOString(),
                    'status' => $order->status,
                    'amount' => $order->total_amount,
                    'customer' => [
                        'name' => $order->user ? 
                            $order->user->first_name . ' ' . $order->user->last_name : 
                            'Client inconnu',
                        'email' => $order->user ? $order->user->email : 'N/A'
                    ]
                ];
            });

        return $recentOrders;
    }
} 