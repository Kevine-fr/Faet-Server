<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\VendorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\VendorResource;
use App\Http\Resources\UserResource;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Requests\VendorRequest;
use Illuminate\Support\Facades\Log;

class VendorsController extends Controller
{
    protected $vendorService;

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    /* ================================
    |          GITHUB (TOP)
    |=============================== */

    /**
     * Display a listing of the resource.
     */
    public function lisVendeur(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 20);
            $status  = $request->get('status'); // Exemple: active / inactive / blocked

            $vendors = $this->vendorService->getAllVendor($perPage, $status);

            return UserResource::collection($vendors)
                ->additional([
                    'meta' => [
                        'current_page' => $vendors->currentPage(),
                        'last_page'    => $vendors->lastPage(),
                        'per_page'     => $vendors->perPage(),
                        'total'        => $vendors->total(),
                    ]
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer la liste des vendeurs.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Afficher les détails d’un vendeur
     */
    public function show($id)
    {
        try {
            $vendor = $this->vendorService->findByUserId($id);

            return response()->json([
                'success' => true,
                'data' => new VendorResource($vendor->load('user')),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendeur introuvable',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storeVendor(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'first_name'       => 'required|string|max:255',
                'last_name'        => 'required|string|max:255',
                'phone'            => ['required', 'string', 'regex:/^(?:\+?\d{1,3})?[0-9]{7,15}$/'],
                'profile_picture'  => 'nullable|string|max:255',
                'email'            => 'required|email|unique:users,email',
                'password'         => 'required|string|min:8',
            ]);

            $client = $this->vendorService->createvendeur($validated);

            return response()->json([
                'message' => 'Vendeur créé avec succès',
                'client'  => $client
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du Vendeur',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* ================================
    |        LOCAL (HEAD) — BOTTOM
    |=============================== */

    /**
     * Récupérer les préférences de notifications du vendeur connecté.
     */
    public function getNotificationPreferences(): JsonResponse
    {
        try {
            $user = Auth::user();
            $result = $this->vendorService->getNotificationPreferences($user->id);

            if (!$result['success']) {
                return response()->json($result, 404);
            }

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des préférences',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour les préférences de notifications du vendeur connecté.
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_notified' => 'required|boolean',
                'is_notified_new_order' => 'required|boolean',
                'is_notified_order_canceled' => 'required|boolean',
                'is_notified_low_stock' => 'required|boolean',
                'is_notified_app_maintenance' => 'required|boolean',
                'is_notified_app_update' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $result = $this->vendorService->updateNotificationPreferences($user->id, $request->all());

            if (!$result['success']) {
                return response()->json($result, 404);
            }

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des préférences',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /* ---- Stubs CRUD pour compatibilité (HEAD) ---- */

    public function index(): JsonResponse
    {
        // Renvoie la même liste que lisVendeur pour compatibilité
        return response()->json([
            'venddor' => $this->vendorService->getAllVendor(),
        ]);
    }

    /**
     * Récupération des détails du vendeur et ses produits
     */
    public function getVendorShop(Request $request, $vendorId)
    {
        try {
            $user = auth()->user();

            $perPage = $request->input('per_page', 12);
            $page = $request->input('page', 1);
            $sortBy = $request->input('sort_by', 'name'); // name, price_asc, price_desc

            // Validation des options de tri
            $validSortOptions = [
                'name', 
                'price_asc', 
                'price_desc', 
                'newest', 
                'recent', 
                'oldest',
            ];

            if (!in_array($sortBy, $validSortOptions)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Option de tri invalide',
                    'valid_options' => $validSortOptions,
                ], 400);
            }

            $data = $this->vendorService->getVendorShop($user, (int) $vendorId, $perPage, $page, $sortBy);

            return response()->json([
                'success' => true,
                'message' => 'Détails du vendeur récupérés avec succès',
                'data' => $data,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendeur introuvable.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails du vendeur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recherche de produits d'un vendeur spécifique
     */
    public function searchVendorProducts(Request $request, $vendorId)
    {
        try {
            $user = auth()->user();

            $search = $request->input('search', '');
            $perPage = $request->input('per_page', 12);
            $page = $request->input('page', 1);
            $sortBy = $request->input('sort_by', 'name');

            if (empty(trim($search))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le terme de recherche ne peut pas être vide.',
                ], 400);
            }

            $data = $this->vendorService->searchVendorProducts($user, (int) $vendorId, $search, $perPage, $page, $sortBy);

            return response()->json([
                'success' => true,
                'message' => 'Recherche effectuée avec succès',
                'data' => $data,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendeur introuvable.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche des produits',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function create(): void
    {
        // Non utilisé en API (placeholder)
    }

    public function store(Request $request): void
    {
        // Utiliser storeVendor() pour la création via API
    }

    public function edit(Vendor $vendor): void
    {
        // Non utilisé en API (placeholder)
    }

    public function update(Request $request, Vendor $vendor): void
    {
        // À implémenter si nécessaire
    }

    public function destroy(Vendor $vendor): void
    {
        // À implémenter si nécessaire
    }

    /**
     * Obtenir la méthode de paiement préférée du vendeur
     */
    public function getPreferredPaymentMethod(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $vendor = $user->vendor;

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'preferred_method' => $vendor->preferred_payment_method ?? 'stripe'
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la méthode préférée: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour la méthode de paiement préférée du vendeur
     */
    public function updatePreferredPaymentMethod(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'preferred_method' => 'required|in:stripe,paypal'
            ]);

            $user = auth('api')->user();
            $vendor = $user->vendor;

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun vendeur trouvé pour cet utilisateur'
                ], 404);
            }

            $vendor->preferred_payment_method = $request->preferred_method;
            $vendor->save();

            return response()->json([
                'success' => true,
                'message' => 'Méthode de paiement préférée mise à jour avec succès',
                'data' => [
                    'preferred_method' => $vendor->preferred_payment_method
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la méthode préférée: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer le statut Stripe d'un vendeur
     */
    public function getStripeStatus($vendorId): JsonResponse
    {
        try {
            $vendor = Vendor::with('user')->find($vendorId);

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendeur introuvable'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor_stripe_id' => $vendor->vendor_stripe_id,
                    'stripe_charges_enabled' => $vendor->stripe_charges_enabled,
                    'stripe_payouts_enabled' => $vendor->stripe_payouts_enabled,
                    'stripe_details_submitted' => $vendor->stripe_details_submitted,
                    'user' => [
                        'email' => $vendor->user->email ?? null
                    ],
                    'updated_at' => $vendor->updated_at
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du statut Stripe: ' . $e->getMessage()
            ], 500);
        }
    }

    public function estore(VendorRequest $request): JsonResponse
    {
        try {
            $vendor = $this->vendorService->createOrUpdateVendor($request->validated());

            return response()->json([
                'message' => 'Vendeur enregistré ou mis à jour avec succès.',
                'data' => $vendor
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la création/mise à jour du vendeur : ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Une erreur est survenue lors du traitement du vendeur.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Met à jour les informations de la boutique (description et website_url)
     */
    public function updateInfo(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                // Champs de la table vendors
                'name_store' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'website_url' => 'nullable|url|max:255',
                'phone_number_store' => 'nullable|string|max:20',
                'landline_phone_store' => 'nullable|string|max:20',
                // Champs de la table users
                'street' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:10',
                'city' => 'nullable|string|max:255',
            ]);

            $result = $this->vendorService->updateVendorInfo(Auth::id(), $validated);

            return response()->json([
                'success' => true,
                'message' => 'Informations de la boutique mises à jour avec succès',
                'data' => new UserResource($result['user'])
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendeur introuvable'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            // Gérer l'erreur d'URL dupliquée
            if (str_contains($e->getMessage(), 'URL de boutique est déjà utilisée')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 422);
            }

            Log::error('Erreur lors de la mise à jour des informations de la boutique : ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}