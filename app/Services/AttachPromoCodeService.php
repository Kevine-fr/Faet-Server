<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\Product;
use App\Models\AttachPromoCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

class AttachPromoCodeService
{
    /**
     * Attacher des produits à un code promo
     */
    public function attachProducts(int $promoCodeId, array $productIds): array
    {
        try {
            Log::info('AttachPromoCodeService: Début de l\'attachement', [
                'promo_code_id' => $promoCodeId,
                'product_ids' => $productIds
            ]);

            DB::beginTransaction();

            // Récupérer le code promo
            $promoCode = PromoCode::findOrFail($promoCodeId);
            Log::info('AttachPromoCodeService: Code promo trouvé', [
                'promo_code' => $promoCode->toArray()
            ]);
            
            // Récupérer les produits
            $products = Product::whereIn('id', $productIds)->get();
            Log::info('AttachPromoCodeService: Produits trouvés', [
                'products_count' => $products->count(),
                'products' => $products->toArray()
            ]);
            
            $attachedProducts = [];
            
            foreach ($products as $product) {
                Log::info('AttachPromoCodeService: Traitement du produit', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $product->price
                ]);

                // Récupérer le prix du produit (prix minimum des variantes si le prix principal est NULL)
                $productPrice = $product->price;
                if ($productPrice === null) {
                    $minVariantPrice = $product->variants()
                        ->whereNotNull('price')
                        ->where('price', '>', 0)
                        ->min('price');
                    
                    if ($minVariantPrice !== null) {
                        $productPrice = $minVariantPrice;
                        Log::info('AttachPromoCodeService: Prix des variantes utilisé', [
                            'product_id' => $product->id,
                            'min_variant_price' => $minVariantPrice
                        ]);
                    } else {
                        Log::warning('AttachPromoCodeService: Aucun prix trouvé pour le produit', [
                            'product_id' => $product->id
                        ]);
                        continue; // Passer au produit suivant
                    }
                }

                // Calculer le prix final
                $finalPrice = $this->calculateFinalPrice($productPrice, $promoCode->type, $promoCode->value);
                Log::info('AttachPromoCodeService: Prix final calculé', [
                    'original_price' => $product->price,
                    'used_price' => $productPrice,
                    'promo_type' => $promoCode->type,
                    'promo_value' => $promoCode->value,
                    'final_price' => $finalPrice
                ]);
                
                // Créer ou mettre à jour l'attachement via la table attach_promo_codes
                $attachPromoCode = AttachPromoCode::updateOrCreate(
                    [
                        'promo_code_id' => $promoCodeId,
                        'product_id' => $product->id
                    ],
                    [
                        'final_price' => $finalPrice
                    ]
                );
                
                Log::info('AttachPromoCodeService: Attachement créé/mis à jour', [
                    'attach_id' => $attachPromoCode->id,
                    'promo_code_id' => $promoCodeId,
                    'product_id' => $product->id,
                    'final_price' => $finalPrice
                ]);
                
                $attachedProducts[] = [
                    'id' => $attachPromoCode->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'original_price' => $product->price,
                    'used_price' => $productPrice,
                    'final_price' => $finalPrice,
                    'discount_amount' => $productPrice - $finalPrice
                ];
            }
            
            DB::commit();
            
            Log::info('AttachPromoCodeService: Attachement terminé avec succès', [
                'promo_code_id' => $promoCodeId,
                'products_count' => count($attachedProducts),
                'products' => $attachedProducts
            ]);
            
            return $attachedProducts;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AttachPromoCodeService: Erreur lors de l\'attachement des produits', [
                'promo_code_id' => $promoCodeId,
                'product_ids' => $productIds,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Détacher un produit d'un code promo
     */
    public function detachProduct(int $promoCodeId, int $productId): bool
    {
        try {
            Log::info('AttachPromoCodeService: Début du détachement', [
                'promo_code_id' => $promoCodeId,
                'product_id' => $productId
            ]);

            $deleted = AttachPromoCode::where('promo_code_id', $promoCodeId)
                                     ->where('product_id', $productId)
                                     ->delete();

            Log::info('AttachPromoCodeService: Détachement terminé', [
                'deleted_count' => $deleted
            ]);

            return $deleted > 0;

        } catch (\Exception $e) {
            Log::error('AttachPromoCodeService: Erreur lors du détachement', [
                'promo_code_id' => $promoCodeId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer les produits attachés à un code promo
     */
    public function getAttachedProducts(int $promoCodeId): Collection
    {
        try {
            $promoCode = PromoCode::with(['products' => function($query) {
                $query->select('products.*', 'attach_promo_codes.final_price as pivot_final_price');
            }])->findOrFail($promoCodeId);

            return $promoCode->products;

        } catch (\Exception $e) {
            Log::error('AttachPromoCodeService: Erreur lors de la récupération des produits attachés', [
                'promo_code_id' => $promoCodeId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer les codes promo attachés à un produit
     */
    public function getProductPromoCodes(int $productId): Collection
    {
        try {
            $product = Product::with(['promoCodes' => function($query) {
                $query->select('promo_codes.*', 'attach_promo_codes.final_price as pivot_final_price');
            }])->findOrFail($productId);

            return $product->promoCodes;

        } catch (\Exception $e) {
            Log::error('AttachPromoCodeService: Erreur lors de la récupération des codes promo attachés', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Calculer le prix final d'un produit avec un code promo
     */
    public function calculateFinalPrice(?float $productPrice, string $promoType, float $promoValue): float
    {
        // Si le prix est null, retourner 0
        if ($productPrice === null) {
            return 0.0;
        }

        switch ($promoType) {
            case 'percentage':
                $finalPrice = $productPrice * (1 - ($promoValue / 100));
                break;
            case 'fixed':
                $finalPrice = $productPrice - $promoValue;
                break;
            default:
                $finalPrice = $productPrice;
                break;
        }

        // S'assurer que le prix final n'est pas négatif
        return round(max(0, $finalPrice), 2);
    }

    /**
     * Récupérer le nombre de produits attachés à un code promo
     */
    public function getAttachedProductsCount(int $promoCodeId): int
    {
        return AttachPromoCode::where('promo_code_id', $promoCodeId)->count();
    }

    /**
     * Récupérer le nombre de codes promo attachés à un produit
     */
    public function getProductPromoCodesCount(int $productId): int
    {
        return AttachPromoCode::where('product_id', $productId)->count();
    }

    /**
     * Récupérer tous les produits déjà attachés à n'importe quel code promo du vendeur
     */
    public function getAllAttachedProducts(int $vendorId): array
    {
        try {
            Log::info('AttachPromoCodeService: Récupération de tous les produits attachés', [
                'vendor_id' => $vendorId
            ]);

            // Récupérer tous les produits attachés du vendeur
            $attachedProducts = AttachPromoCode::join('products', 'attach_promo_codes.product_id', '=', 'products.id')
                ->join('promo_codes', 'attach_promo_codes.promo_code_id', '=', 'promo_codes.id')
                ->where('products.vendor_id', $vendorId)
                ->select([
                    'attach_promo_codes.id',
                    'attach_promo_codes.product_id',
                    'attach_promo_codes.promo_code_id',
                    'attach_promo_codes.final_price',
                    'products.name as product_name',
                    'products.sku as product_sku',
                    'promo_codes.code as promo_code',
                    'promo_codes.name as promo_name'
                ])
                ->get()
                ->toArray();

            Log::info('AttachPromoCodeService: Produits attachés récupérés', [
                'vendor_id' => $vendorId,
                'count' => count($attachedProducts)
            ]);

            return $attachedProducts;

        } catch (\Exception $e) {
            Log::error('AttachPromoCodeService: Erreur lors de la récupération de tous les produits attachés', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
