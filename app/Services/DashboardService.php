<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getStats(): array
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // 1. Commandes
        $totalOrders = Order::count();
        $ordersToday = Order::whereDate('created_at', $today)->count();
        $ordersThisMonth = Order::whereBetween('created_at', [$startOfMonth, Carbon::now()])->count();
        $ordersByStatus = Order::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // 2. Chiffre d’affaires
        $revenueTotal = Order::where('payment_status', 'complete')->sum('total_amount');
        $revenueToday = Order::where('payment_status', 'complete')
            ->whereDate('created_at', $today)
            ->sum('total_amount');
        $revenueThisMonth = Order::where('payment_status', 'complete')
            ->whereBetween('created_at', [$startOfMonth, Carbon::now()])
            ->sum('total_amount');

        // 3. Utilisateurs
        $totalUsers = User::count();
        $newUsersToday = User::whereDate('created_at', $today)->count();
        $usersWithOrders = User::has('orders')->count();

        // 4. Produits
        $totalProducts = Product::count();

        // Produits en rupture de stock = aucun variant avec stock_quantity > 0
        $outOfStock = Product::whereDoesntHave('variants', function ($q) {
            $q->where('stock_quantity', '>', 0);
        })->count();

        // 5. Transactions
        $transactions = Transaction::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // 6. Graphiques
        $salesLast30Days = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as total')
            )
            ->where('payment_status', 'complete')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as total_sold'))
            ->where('orders.payment_status', 'complete') // ✅ filtrage logique
            ->groupBy('order_items.product_id')
            ->orderByDesc('total_sold')
            ->take(5)
            ->get();

        return [
            'orders' => [
                'total' => $totalOrders,
                'today' => $ordersToday,
                'this_month' => $ordersThisMonth,
                'by_status' => $ordersByStatus,
            ],
            'revenue' => [
                'total' => $revenueTotal,
                'today' => $revenueToday,
                'this_month' => $revenueThisMonth,
            ],
            'users' => [
                'total' => $totalUsers,
                'new_today' => $newUsersToday,
                'with_orders' => $usersWithOrders,
            ],
            'products' => [
                'total' => $totalProducts,
                'out_of_stock' => $outOfStock,
            ],
            'transactions' => $transactions,
            'charts' => [
                'sales_last_30_days' => $salesLast30Days,
                'top_products' => $topProducts,
            ],
        ];
    }
}