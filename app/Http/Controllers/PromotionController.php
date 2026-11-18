<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Promotion;
use App\Services\PromotionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionController extends Controller
{
    protected PromotionService $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        $this->promotionService = $promotionService;
    }

    /**
     * Liste toutes les promotions du vendeur connecté
     */
    public function index(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotions = $this->promotionService->listPromotions($vendorId);
            
            return response()->json([
                'success' => true,
                'data' => $promotions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des promotions: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des promotions'
            ], 500);
        }
    }

    /**
     * Récupérer les promotions actives
     */
    public function active(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotions = $this->promotionService->getActivePromotions($vendorId);
            
            return response()->json([
                'success' => true,
                'data' => $promotions
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des promotions actives: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des promotions actives'
            ], 500);
        }
    }

    /**
     * Récupérer une promotion spécifique
     */
    public function show(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotion = $this->promotionService->getPromotion($id, $vendorId);
            
            if (!$promotion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Promotion non trouvée'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $promotion
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la promotion'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle promotion
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();
            
            $promotion = $this->promotionService->createPromotion($data, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Promotion créée avec succès',
                'data' => $promotion
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la promotion: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour une promotion
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $data = $request->all();
            
            $promotion = $this->promotionService->updatePromotion($id, $data, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Promotion mise à jour avec succès',
                'data' => $promotion
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une promotion
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            
            $this->promotionService->deletePromotion($id, $vendorId);
            
            return response()->json([
                'success' => true,
                'message' => 'Promotion supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Changer le statut actif/inactif d'une promotion
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $promotion = $this->promotionService->toggleStatus($id, $vendorId);
            
            $status = $promotion->is_active ? 'activée' : 'désactivée';
            
            return response()->json([
                'success' => true,
                'message' => "Promotion $status avec succès",
                'data' => $promotion
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors du changement de statut de la promotion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les statistiques des promotions
     */
    public function getStats(): JsonResponse
    {
        try {
            $vendorId = Auth::user()->vendor->id;
            $stats = $this->promotionService->getPromotionStats($vendorId);
            
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
     * Vérifier s'il existe des promotions ou codes promos actifs
     * (pour afficher ou non le banner)
     */
    public function hasActivePromotions()
    {
        $now = Carbon::now();
        
        // Vérifier codes promos actifs
        $hasActiveCodes = PromoCode::where('is_active', true)
            ->where('status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereRaw('used_count < max_uses');
            })
            ->exists();
        
        // Vérifier promotions actives
        $hasActivePromotions = Promotion::where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now)
            ->exists();
        
        return response()->json([
            'success' => true,
            'data' => [
                'has_active_promos' => $hasActiveCodes,
                'has_active_promotions' => $hasActivePromotions,
                'show_banner' => $hasActiveCodes || $hasActivePromotions
            ]
        ]);
    }

    /**
     * Récupérer tous les produits en promotion ou avec code promo
     * (pour la page /promotions)
     */
    public function getPromotionProducts(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 12);
            $sortBy = $request->input('sort_by', 'discount_desc'); // discount_desc, price_asc, price_desc, name
            $user = auth()->user();
            $now = Carbon::now();

            // IDs des produits en promotion (table promotions)
            $promotionProductIds = DB::table('promotion_product')
                ->join('promotions', 'promotion_product.promotion_id', '=', 'promotions.id')
                ->where('promotions.is_active', true)
                ->where('promotions.start_date', '<=', $now)
                ->where('promotions.end_date', '>=', $now)
                ->pluck('promotion_product.product_id')
                ->toArray();

            // IDs des produits avec codes promos
            $promoCodeProductIds = DB::table('attach_promo_codes')
                ->join('promo_codes', 'attach_promo_codes.promo_code_id', '=', 'promo_codes.id')
                ->where('promo_codes.is_active', true)
                ->where('promo_codes.status', 'active')
                ->where(function ($q) use ($now) {
                    $q->whereNull('promo_codes.valid_from')->orWhere('promo_codes.valid_from', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('promo_codes.valid_until')->orWhere('promo_codes.valid_until', '>=', $now);
                })
                ->pluck('attach_promo_codes.product_id')
                ->toArray();

            // Fusionner les IDs uniques
            $allPromoProductIds = array_unique(array_merge($promotionProductIds, $promoCodeProductIds));

            if (empty($allPromoProductIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'products' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                        ]
                    ]
                ]);
            }

            // Requête des produits
            $query = Product::with([
                'category:id,name',
                'vendor:id,name_store',
                'images' => function ($q) {
                    $q->orderBy('is_primary', 'desc')->orderBy('position')->limit(3);
                },
                'variants' => function ($q) {
                    $q->where('is_active', true)->select('product_id', 'price');
                }
            ])
                ->whereIn('id', $allPromoProductIds)
                ->where('is_active', true)
                ->where('is_blocked', false);

            // Ajouter les infos de promotion pour chaque produit
            $query->select([
                'products.*',
                DB::raw('COALESCE(products.price, (
                SELECT MIN(pv.price)
                FROM product_variants pv
                WHERE pv.product_id = products.id
                AND pv.is_active = TRUE
            )) as effective_price'),
                // Récupérer la promo active
                DB::raw("(
                SELECT promotions.discount_value
                FROM promotion_product
                JOIN promotions ON promotion_product.promotion_id = promotions.id
                WHERE promotion_product.product_id = products.id
                AND promotions.is_active = TRUE
                AND promotions.start_date <= NOW()
                AND promotions.end_date >= NOW()
                LIMIT 1
            ) as promotion_discount"),
                DB::raw("(
                SELECT promotions.discount_type
                FROM promotion_product
                JOIN promotions ON promotion_product.promotion_id = promotions.id
                WHERE promotion_product.product_id = products.id
                AND promotions.is_active = TRUE
                AND promotions.start_date <= NOW()
                AND promotions.end_date >= NOW()
                LIMIT 1
            ) as promotion_discount_type"),
                // Récupérer le code promo si existe
                DB::raw("(
                SELECT promo_codes.code
                FROM attach_promo_codes
                JOIN promo_codes ON attach_promo_codes.promo_code_id = promo_codes.id
                WHERE attach_promo_codes.product_id = products.id
                AND promo_codes.is_active = TRUE
                AND promo_codes.status = 'active'
                LIMIT 1
            ) as promo_code"),
            ]);

            // Tri
            switch ($sortBy) {
                case 'discount_desc':
                    $query->orderByRaw('promotion_discount DESC NULLS LAST');
                    break;
                case 'price_asc':
                    $query->orderBy('effective_price', 'asc');
                    break;
                case 'price_desc':
                    $query->orderBy('effective_price', 'desc');
                    break;
                case 'name':
                    $query->orderByRaw('LOWER(name) ASC');
                    break;
            }

            $products = $query->paginate($perPage);

            // Ajouter is_favorite et calculer les prix
            if ($user) {
                $productIds = $products->pluck('id')->toArray();
                $favoriteIds = DB::table('favorites')
                    ->where('user_id', $user->id)
                    ->whereIn('product_id', $productIds)
                    ->whereNull('deleted_at')
                    ->pluck('product_id')
                    ->toArray();

                foreach ($products as $product) {
                    $product->is_favorite = in_array($product->id, $favoriteIds);
                    $this->calculatePromoPrices($product);
                }
            } else {
                foreach ($products as $product) {
                    $product->is_favorite = false;
                    $this->calculatePromoPrices($product);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $products->items(),
                    'pagination' => [
                        'current_page' => $products->currentPage(),
                        'last_page' => $products->lastPage(),
                        'per_page' => $products->perPage(),
                        'total' => $products->total(),
                    ]
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching promotion products: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des produits en promotion',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Calculer les prix avec promotion
     */
    private function calculatePromoPrices($product)
    {
        $originalPrice = $product->effective_price;
        
        if (!$originalPrice) {
            $product->original_price = null;
            $product->promo_price = null;
            $product->discount_percentage = null;
            return;
        }
        
        $product->original_price = (float) $originalPrice;
        
        // Si promotion active
        if ($product->promotion_discount && $product->promotion_discount_type) {
            if ($product->promotion_discount_type === 'percentage') {
                $discount = ($originalPrice * $product->promotion_discount) / 100;
            } else {
                $discount = min($product->promotion_discount, $originalPrice);
            }
            
            $product->promo_price = round(max(0, $originalPrice - $discount), 2);
            $product->discount_percentage = round(($discount / $originalPrice) * 100, 0);
            $product->has_promotion = true;
        } else {
            $product->promo_price = $originalPrice;
            $product->discount_percentage = 0;
            $product->has_promotion = false;
        }
        
        // Indiquer si le produit a un code promo disponible
        $product->has_promo_code = !empty($product->promo_code);
    }
}
