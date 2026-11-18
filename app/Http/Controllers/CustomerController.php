<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Models\VendorSubscription;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Customer;

class CustomerController extends Controller
{
    /**
     * Récupère la liste des clients d'un vendeur
     */
    public function getCustomers(Request $request): JsonResponse
    {
        try {
            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            $vendorId = $user->vendor->id ?? null;
            
            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Récupérer la page demandée et le terme de recherche
            $page = $request->get('page', 1);
            $search = $request->get('search', '');
            $perPage = 10;

            // Récupérer les abonnements du vendeur avec pagination
            $customersQuery = VendorSubscription::where('vendor_id', $vendorId)
                ->whereHas('user.customer') // Filtrer uniquement les utilisateurs qui sont des customers
                ->with(['user:id,first_name,last_name,email,phone,created_at', 'user.customer:user_id,birthdate,gender_preference'])
                ->orderBy('created_at', 'desc');

            // Appliquer le filtre de recherche si fourni
            if (!empty($search)) {
                $searchTerm = trim($search);
                $customersQuery->whereHas('user', function ($query) use ($searchTerm) {
                    $query->where(function ($q) use ($searchTerm) {
                        // Recherche dans le prénom
                        $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                          // Recherche dans le nom
                          ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                          // Recherche dans l'email
                          ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                          // Recherche dans le nom complet (prénom + nom)
                          ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"])
                          // Recherche dans le nom complet inversé (nom + prénom)
                          ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$searchTerm}%"]);
                    });
                });
            }

            // Appliquer la pagination
            $customers = $customersQuery->paginate($perPage, ['*'], 'page', $page);

            // Transformer les données
            $customersData = $customers->getCollection()->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'vendor_id' => $subscription->vendor_id,
                    'subscription_date' => $subscription->created_at,
                    'customer' => [
                        'id' => $subscription->user->id,
                        'name' => $subscription->user->first_name . ' ' . $subscription->user->last_name,
                        'email' => $subscription->user->email,
                        'phone' => $subscription->user->phone, // Le téléphone est maintenant dans la table users
                        'birthdate' => $subscription->user->customer->birthdate ?? null,
                        'gender_preference' => $subscription->user->customer->gender_preference ?? 'tous',
                        'registration_date' => $subscription->user->created_at
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'customers' => $customersData,
                'pagination' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                    'from' => $customers->firstItem(),
                    'to' => $customers->lastItem()
                ],
                'count' => $customersData->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération clients:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des clients'
            ], 500);
        }
    }

    /**
     * Récupère les statistiques des clients
     */
    public function getCustomerStats(Request $request): JsonResponse
    {
        try {
            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            $vendorId = $user->vendor->id ?? null;
            
            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Statistiques des clients (uniquement les customers)
            $totalCustomers = VendorSubscription::where('vendor_id', $vendorId)
                ->whereHas('user.customer')
                ->count();
            
            // Clients ce mois-ci
            $customersThisMonth = VendorSubscription::where('vendor_id', $vendorId)
                ->whereHas('user.customer')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            // Clients le mois dernier
            $customersLastMonth = VendorSubscription::where('vendor_id', $vendorId)
                ->whereHas('user.customer')
                ->whereMonth('created_at', now()->subMonth()->month)
                ->whereYear('created_at', now()->subMonth()->year)
                ->count();

            // Calculer le pourcentage de changement
            $percentageChange = $customersLastMonth > 0 
                ? (($customersThisMonth - $customersLastMonth) / $customersLastMonth) * 100 
                : 0;

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_customers' => $totalCustomers,
                    'customers_this_month' => $customersThisMonth,
                    'customers_last_month' => $customersLastMonth,
                    'percentage_change' => round($percentageChange, 1)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération statistiques clients:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Récupère les détails d'un client spécifique
     */
    public function getCustomerDetails(Request $request, $customerId): JsonResponse
    {
        try {
            // Récupérer l'ID du vendeur depuis l'authentification
            $user = $request->user();
            $vendorId = $user->vendor->id ?? null;
            
            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendeur non trouvé'
                ], 404);
            }

            // Vérifier que le client appartient bien à ce vendeur
            $subscription = VendorSubscription::where('vendor_id', $vendorId)
                ->where('user_id', $customerId)
                ->whereHas('user.customer')
                ->with(['user:id,first_name,last_name,email,phone,created_at', 'user.customer:user_id,birthdate,gender_preference'])
                ->first();

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'error' => 'Client non trouvé'
                ], 404);
            }

            $customerData = [
                'id' => $subscription->user->id,
                'name' => $subscription->user->first_name . ' ' . $subscription->user->last_name,
                'email' => $subscription->user->email,
                'phone' => $subscription->user->phone,
                'birthdate' => $subscription->user->customer->birthdate ?? null,
                'gender_preference' => $subscription->user->customer->gender_preference ?? 'tous',
                'registration_date' => $subscription->user->created_at,
                'subscription_date' => $subscription->created_at
            ];

            return response()->json([
                'success' => true,
                'customer' => $customerData
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération détails client:', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des détails du client'
            ], 500);
        }
    }
} 