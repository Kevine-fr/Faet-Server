<?php

namespace App\Services;

use App\Models\PromotionProduct;
use App\Models\Promotion;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PromotionProductService
{
    /**
     * Liste toutes les associations promotion-produit d'un vendeur
     */
    public function listPromotionProducts(int $vendorId): Collection
    {
        return PromotionProduct::whereHas('promotion', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
        ->with(['promotion', 'product:id,name,sku,price'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Récupérer une association par ID
     */
    public function getPromotionProduct(int $id, int $vendorId): ?PromotionProduct
    {
        return PromotionProduct::whereHas('promotion', function ($query) use ($vendorId) {
            $query->where('vendor_id', $vendorId);
        })
        ->with(['promotion', 'product:id,name,sku,price'])
        ->find($id);
    }

    /**
     * Récupérer tous les produits d'une promotion
     */
    public function getPromotionProducts(int $promotionId, int $vendorId): Collection
    {
        // Vérifier que la promotion appartient au vendeur
        $promotion = Promotion::where('id', $promotionId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        return PromotionProduct::where('promotion_id', $promotionId)
            ->with(['product:id,name,sku,price'])
            ->get();
    }

    /**
     * Récupérer toutes les promotions d'un produit
     */
    public function getProductPromotions(int $productId, int $vendorId): Collection
    {
        // Vérifier que le produit appartient au vendeur
        $product = Product::where('id', $productId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        return PromotionProduct::where('product_id', $productId)
            ->with(['promotion'])
            ->get();
    }

    /**
     * Créer une nouvelle association promotion-produit
     */
    public function createPromotionProduct(array $data, int $vendorId): PromotionProduct
    {
        $this->validatePromotionProductData($data, $vendorId);

        DB::beginTransaction();
        
        try {
            // Vérifier que la promotion appartient au vendeur
            $promotion = Promotion::where('id', $data['promotion_id'])
                ->where('vendor_id', $vendorId)
                ->firstOrFail();

            // Vérifier que le produit appartient au vendeur
            $product = Product::where('id', $data['product_id'])
                ->where('vendor_id', $vendorId)
                ->firstOrFail();

            // Vérifier si l'association existe déjà
            $exists = PromotionProduct::where('promotion_id', $data['promotion_id'])
                ->where('product_id', $data['product_id'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'product_id' => 'Cette association existe déjà.'
                ]);
            }

            $promotionProduct = PromotionProduct::create([
                'promotion_id' => $data['promotion_id'],
                'product_id' => $data['product_id'],
            ]);

            DB::commit();
            return $promotionProduct->load(['promotion', 'product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Mettre à jour une association promotion-produit
     */
    public function updatePromotionProduct(int $id, array $data, int $vendorId): PromotionProduct
    {
        $promotionProduct = $this->getPromotionProduct($id, $vendorId);
        
        if (!$promotionProduct) {
            throw new \Exception('Association non trouvée.');
        }

        $this->validatePromotionProductData($data, $vendorId, $id);

        DB::beginTransaction();
        
        try {
            // Si on change la promotion ou le produit, vérifier qu'ils appartiennent au vendeur
            if (isset($data['promotion_id']) && $data['promotion_id'] != $promotionProduct->promotion_id) {
                $promotion = Promotion::where('id', $data['promotion_id'])
                    ->where('vendor_id', $vendorId)
                    ->firstOrFail();
            }

            if (isset($data['product_id']) && $data['product_id'] != $promotionProduct->product_id) {
                $product = Product::where('id', $data['product_id'])
                    ->where('vendor_id', $vendorId)
                    ->firstOrFail();

                // Vérifier si la nouvelle association existe déjà
                $exists = PromotionProduct::where('promotion_id', $data['promotion_id'] ?? $promotionProduct->promotion_id)
                    ->where('product_id', $data['product_id'])
                    ->where('id', '!=', $id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'product_id' => 'Cette association existe déjà.'
                    ]);
                }
            }

            $promotionProduct->update([
                'promotion_id' => $data['promotion_id'] ?? $promotionProduct->promotion_id,
                'product_id' => $data['product_id'] ?? $promotionProduct->product_id,
            ]);

            DB::commit();
            return $promotionProduct->load(['promotion', 'product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Supprimer une association promotion-produit
     */
    public function deletePromotionProduct(int $id, int $vendorId): void
    {
        $promotionProduct = $this->getPromotionProduct($id, $vendorId);
        
        if (!$promotionProduct) {
            throw new \Exception('Association non trouvée.');
        }

        $promotionProduct->delete();
    }

    /**
     * Ajouter des produits à une promotion
     */
    public function attachProductsToPromotion(int $promotionId, array $productIds, int $vendorId): array
    {
        // Vérifier que la promotion appartient au vendeur
        $promotion = Promotion::where('id', $promotionId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        // Valider les IDs de produits
        $this->validateProductIds($productIds, $vendorId);

        DB::beginTransaction();
        
        try {
            $attached = [];
            
            foreach ($productIds as $productId) {
                // Vérifier si l'association existe déjà
                $exists = PromotionProduct::where('promotion_id', $promotionId)
                    ->where('product_id', $productId)
                    ->exists();
                
                if (!$exists) {
                    PromotionProduct::create([
                        'promotion_id' => $promotionId,
                        'product_id' => $productId,
                    ]);
                    $attached[] = $productId;
                }
            }

            DB::commit();
            
            return [
                'success' => true,
                'attached' => $attached,
                'total' => count($attached)
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Retirer des produits d'une promotion
     */
    public function detachProductsFromPromotion(int $promotionId, array $productIds, int $vendorId): array
    {
        // Vérifier que la promotion appartient au vendeur
        $promotion = Promotion::where('id', $promotionId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        DB::beginTransaction();
        
        try {
            $detached = PromotionProduct::where('promotion_id', $promotionId)
                ->whereIn('product_id', $productIds)
                ->delete();

            DB::commit();
            
            return [
                'success' => true,
                'detached' => $detached
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Synchroniser les produits d'une promotion (remplace tous les produits)
     */
    public function syncPromotionProducts(int $promotionId, array $productIds, int $vendorId): array
    {
        // Vérifier que la promotion appartient au vendeur
        $promotion = Promotion::where('id', $promotionId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        // Valider les IDs de produits
        if (!empty($productIds)) {
            $this->validateProductIds($productIds, $vendorId);
        }

        DB::beginTransaction();
        
        try {
            // Supprimer toutes les associations existantes
            PromotionProduct::where('promotion_id', $promotionId)->delete();

            // Créer les nouvelles associations
            $attached = [];
            foreach ($productIds as $productId) {
                PromotionProduct::create([
                    'promotion_id' => $promotionId,
                    'product_id' => $productId,
                ]);
                $attached[] = $productId;
            }

            DB::commit();
            
            return [
                'success' => true,
                'attached' => $attached,
                'total' => count($attached)
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Valider les données de l'association promotion-produit
     */
    private function validatePromotionProductData(array $data, int $vendorId, ?int $id = null): void
    {
        $rules = [
            'promotion_id' => 'required|integer|exists:promotions,id',
            'product_id' => 'required|integer|exists:products,id',
        ];

        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Vérifier que la promotion appartient au vendeur
        $promotion = Promotion::where('id', $data['promotion_id'])
            ->where('vendor_id', $vendorId)
            ->first();

        if (!$promotion) {
            throw ValidationException::withMessages([
                'promotion_id' => 'Cette promotion ne vous appartient pas.'
            ]);
        }

        // Vérifier que le produit appartient au vendeur
        $product = Product::where('id', $data['product_id'])
            ->where('vendor_id', $vendorId)
            ->first();

        if (!$product) {
            throw ValidationException::withMessages([
                'product_id' => 'Ce produit ne vous appartient pas.'
            ]);
        }

        // Vérifier que l'association n'existe pas déjà (sauf pour la mise à jour)
        $exists = PromotionProduct::where('promotion_id', $data['promotion_id'])
            ->where('product_id', $data['product_id']);

        if ($id) {
            $exists->where('id', '!=', $id);
        }

        if ($exists->exists()) {
            throw ValidationException::withMessages([
                'product_id' => 'Cette association existe déjà.'
            ]);
        }
    }

    /**
     * Valider que les produits appartiennent au vendeur
     */
    private function validateProductIds(array $productIds, int $vendorId): void
    {
        $validator = Validator::make(['product_ids' => $productIds], [
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:products,id'
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Vérifier que tous les produits appartiennent au vendeur
        $invalidProducts = Product::whereIn('id', $productIds)
            ->where('vendor_id', '!=', $vendorId)
            ->pluck('id')
            ->toArray();

        if (!empty($invalidProducts)) {
            throw ValidationException::withMessages([
                'product_ids' => 'Certains produits ne vous appartiennent pas: ' . implode(', ', $invalidProducts)
            ]);
        }
    }
}
