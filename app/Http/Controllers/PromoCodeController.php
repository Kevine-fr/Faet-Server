<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PromoCodeAdminResource;
use App\Http\Resources\PromoCodeShowResource;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromoCodeController extends Controller
{
    protected PromoCodeService $promoCodeService;

    public function __construct(PromoCodeService $promoCodeService)
    {
        $this->promoCodeService = $promoCodeService;
    }

    /**
     * Récupérer tous les codes promo du vendeur connecté
     */
    public function index(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promoCodes = $this->promoCodeService->getVendorPromoCodes($vendorId);

            return response()->json([
                'success' => true,
                'data' => $promoCodes
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des codes promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des codes promo'
            ], 500);
        }
    }

    /**
     * Récupérer un code promo spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promoCode = $this->promoCodeService->getPromoCode($id, $vendorId);

            if (!$promoCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code promo non trouvé'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $promoCode
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du code promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du code promo'
            ], 500);
        }
    }

    /**
     * Créer un nouveau code promo
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();

            $promoCode = $this->promoCodeService->createPromoCode($data, $vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Code promo créé avec succès',
                'data' => $promoCode
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du code promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du code promo'
            ], 500);
        }
    }

    /**
     * Mettre à jour un code promo
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();

            $promoCode = $this->promoCodeService->updatePromoCode($id, $data, $vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Code promo mis à jour avec succès',
                'data' => $promoCode
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du code promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un code promo
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;

            $this->promoCodeService->deletePromoCode($id, $vendorId);

            return response()->json([
                'success' => true,
                'message' => 'Code promo supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du code promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Changer le statut actif/inactif d'un code promo
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promoCode = $this->promoCodeService->toggleStatus($id, $vendorId);

            $status = $promoCode->is_active ? 'activé' : 'désactivé';
            $statusLabel = $promoCode->status;

            return response()->json([
                'success' => true,
                'message' => "Code promo $status avec succès (statut: $statusLabel)",
                'data' => $promoCode
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors du changement de statut du code promo: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les produits disponibles pour le vendeur
     */
    public function getAvailableProducts(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $products = $this->promoCodeService->getAvailableProducts($vendorId);

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des produits: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits'
            ], 500);
        }
    }

    /**
     * Générer un code promo unique
     */
    public function generateCode(): JsonResponse
    {
        try {
            $code = $this->promoCodeService->generateUniqueCode();

            return response()->json([
                'success' => true,
                'data' => ['code' => $code]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la génération du code: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du code'
            ], 500);
        }
    }

    /**
     * Récupérer les statistiques des codes promo
     */
    public function getStats(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $stats = $this->promoCodeService->getPromoCodeStats($vendorId);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des statistiques: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Récupérer tous les codes promos actifs (pour le banner/carrousel)
     */
    public function getActivePromoCodes()
    {
        $promoCodes = PromoCode::where('is_active', true)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('max_uses')
                    ->orWhereRaw('used_count < max_uses');
            })
            ->with('vendor:id,name_store')
            ->select('id', 'code', 'name', 'description', 'type', 'value', 'vendor_id', 'valid_until', 'min_order_amount')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promoCodes
        ]);
    }

    /**
     * Récupérer les codes promos disponibles pour une liste de produits
     * (appelé depuis la page panier)
     */
    public function getAvailablePromoCodesForProducts(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $productIds = $request->input('product_ids');
        $now = Carbon::now();

        // Récupérer les produits avec leurs codes promos actifs
        $productsWithPromoCodes = DB::table('attach_promo_codes')
            ->join('promo_codes', 'attach_promo_codes.promo_code_id', '=', 'promo_codes.id')
            ->join('products', 'attach_promo_codes.product_id', '=', 'products.id')
            ->whereIn('attach_promo_codes.product_id', $productIds)
            ->where('promo_codes.is_active', true)
            ->where('promo_codes.status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('promo_codes.valid_from')
                  ->orWhere('promo_codes.valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('promo_codes.valid_until')
                  ->orWhere('promo_codes.valid_until', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('promo_codes.max_uses')
                  ->orWhereRaw('promo_codes.used_count < promo_codes.max_uses');
            })
            ->select([
                'attach_promo_codes.product_id',
                'promo_codes.id as promo_code_id',
                'promo_codes.code',
                'promo_codes.name',
                'promo_codes.type',
                'promo_codes.value',
                'promo_codes.vendor_id',
                'promo_codes.min_order_amount',
            ])
            ->get()
            ->groupBy('vendor_id');

        return response()->json([
            'success' => true,
            'data' => $productsWithPromoCodes->map(function ($codes, $vendorId) {
                return [
                    'vendor_id' => $vendorId,
                    'promo_codes' => $codes->unique('code')->values(),
                    'product_ids' => $codes->pluck('product_id')->unique()->values(),
                ];
            })->values()
        ]);
    }

    /**
     * Valider un code promo pour un panier spécifique (groupé par vendor)
     */
    public function validatePromoCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'vendor_id' => 'required|exists:vendors,id',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0'
        ]);

        try {
            $code = strtoupper($request->code);
            $vendorId = $request->vendor_id;
            $items = $request->items;

            $promoCode = PromoCode::where('code', $code)
                ->where('vendor_id', $vendorId)
                ->where('is_active', true)
                ->where('status', 'active')
                ->first();

            if (!$promoCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce code promo n\'est pas valide pour cette boutique'
                ], 404);
            }

            // Vérifications temporelles
            if ($promoCode->valid_from && Carbon::parse($promoCode->valid_from)->isFuture()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce code promo n\'est pas encore actif'
                ], 400);
            }

            if ($promoCode->valid_until && Carbon::parse($promoCode->valid_until)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce code promo a expiré'
                ], 400);
            }

            if ($promoCode->max_uses && $promoCode->used_count >= $promoCode->max_uses) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce code promo a atteint sa limite d\'utilisation'
                ], 400);
            }

            // Récupérer les produits éligibles
            $eligibleProductIds = $promoCode->products()->pluck('products.id')->toArray();

            $subtotal = 0;
            $discount = 0;
            $affectedItems = [];

            foreach ($items as $item) {
                $itemTotal = $item['price'] * $item['quantity'];
                $subtotal += $itemTotal;

                if (in_array($item['product_id'], $eligibleProductIds)) {
                    if ($promoCode->type === 'percentage') {
                        $itemDiscount = ($itemTotal * $promoCode->value) / 100;
                    } else {
                        $itemDiscount = min($promoCode->value, $itemTotal);
                    }

                    $discount += $itemDiscount;

                    $affectedItems[] = [
                        'product_id' => $item['product_id'],
                        'variant_id' => $item['variant_id'] ?? null,
                        'original_total' => $itemTotal,
                        'discount' => round($itemDiscount, 2),
                        'final_total' => round($itemTotal - $itemDiscount, 2),
                    ];
                }
            }

            if ($promoCode->min_order_amount && $subtotal < $promoCode->min_order_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant minimum de {$promoCode->min_order_amount} CFA requis"
                ], 400);
            }

            if (empty($affectedItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun produit de votre panier n\'est éligible à ce code promo'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'promo_code_id' => $promoCode->id,
                    'code' => $promoCode->code,
                    'name' => $promoCode->name,
                    'type' => $promoCode->type,
                    'value' => $promoCode->value,
                    'discount_amount' => round($discount, 2),
                    'affected_items' => $affectedItems,
                    'vendor_id' => $promoCode->vendor_id,
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation du code promo',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Liste des codes promos avec pagination.
     */
    public function indexAdmin(Request $request)
    {
        $perPage = $request->get('per_page', 20);

        $promoCodes = $this->promoCodeService->getAllPaginated($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => PromoCodeAdminResource::collection($promoCodes),
            'links'  => [
                'first' => $promoCodes->url(1),
                'last'  => $promoCodes->url($promoCodes->lastPage()),
                'prev'  => $promoCodes->previousPageUrl(),
                'next'  => $promoCodes->nextPageUrl(),
            ],
            'meta'   => [
                'current_page' => $promoCodes->currentPage(),
                'from'         => $promoCodes->firstItem(),
                'last_page'    => $promoCodes->lastPage(),
                'per_page'     => $promoCodes->perPage(),
                'to'           => $promoCodes->lastItem(),
                'total'        => $promoCodes->total(),
            ],
        ]);
    }

    public function showAdmin($id)
    {
        $promoCode = $this->promoCodeService->findById($id);

        return response()->json([
            'status' => 'success',
            'data'   => new PromoCodeShowResource($promoCode)
        ]);
    }


}
