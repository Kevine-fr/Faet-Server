<?php

namespace App\Services;

use App\Models\Promotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class PromotionService
{
    /**
     * Liste toutes les promotions d'un vendeur
     */
    public function listPromotions(int $vendorId): Collection
    {
        return Promotion::forVendor($vendorId)
            ->with(['products:id,name,sku,price'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Récupérer une promotion par ID
     */
    public function getPromotion(int $id, int $vendorId): ?Promotion
    {
        return Promotion::forVendor($vendorId)
            ->with(['products:id,name,sku,price'])
            ->find($id);
    }

    /**
     * Récupérer les promotions actives d'un vendeur
     */
    public function getActivePromotions(int $vendorId): Collection
    {
        return Promotion::forVendor($vendorId)
            ->valid()
            ->with(['products:id,name,sku,price'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Créer une nouvelle promotion
     */
    public function createPromotion(array $data, int $vendorId): Promotion
    {
        // Récupérer les dates depuis title_promotions via title_promotion_id
        if (isset($data['title_promotion_id'])) {
            $titlePromotion = \App\Models\TitlePromotion::find($data['title_promotion_id']);
            
            if ($titlePromotion) {
                $data['start_date'] = $titlePromotion->start_date;
                $data['end_date'] = $titlePromotion->end_date;
                $data['title'] = $titlePromotion->title; // Utiliser le title de la title_promotion
            } else {
                throw ValidationException::withMessages([
                    'title_promotion_id' => 'La promotion sélectionnée n\'existe pas dans title_promotions.'
                ]);
            }
        } elseif (isset($data['title'])) {
            // Fallback : récupérer par title si title_promotion_id n'est pas fourni
            $titlePromotion = \App\Models\TitlePromotion::where('title', $data['title'])->first();
            
            if ($titlePromotion) {
                $data['title_promotion_id'] = $titlePromotion->id;
                $data['start_date'] = $titlePromotion->start_date;
                $data['end_date'] = $titlePromotion->end_date;
            } else {
                throw ValidationException::withMessages([
                    'title' => 'La promotion sélectionnée n\'existe pas dans title_promotions.'
                ]);
            }
        }
        
        $this->validatePromotionData($data);

        DB::beginTransaction();
        
        try {
            $promotion = Promotion::create([
                'vendor_id' => $vendorId,
                'title_promotion_id' => $data['title_promotion_id'] ?? null,
                'title' => $data['title'],
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Attacher les produits si fournis
            if (!empty($data['product_ids'])) {
                $this->validateProductIds($data['product_ids'], $vendorId);
                $promotion->products()->attach($data['product_ids']);
            }

            DB::commit();
            return $promotion->load('products');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Mettre à jour une promotion
     */
    public function updatePromotion(int $id, array $data, int $vendorId): Promotion
    {
        $promotion = $this->getPromotion($id, $vendorId);
        
        if (!$promotion) {
            throw new \Exception('Promotion non trouvée.');
        }

        // Récupérer les dates depuis title_promotions via title_promotion_id
        if (isset($data['title_promotion_id'])) {
            $titlePromotion = \App\Models\TitlePromotion::find($data['title_promotion_id']);
            
            if ($titlePromotion) {
                $data['start_date'] = $titlePromotion->start_date;
                $data['end_date'] = $titlePromotion->end_date;
                $data['title'] = $titlePromotion->title; // Utiliser le title de la title_promotion
            } else {
                throw ValidationException::withMessages([
                    'title_promotion_id' => 'La promotion sélectionnée n\'existe pas dans title_promotions.'
                ]);
            }
        } elseif (isset($data['title']) && (!isset($data['start_date']) || !isset($data['end_date']))) {
            // Fallback : récupérer par title si title_promotion_id n'est pas fourni
            $titlePromotion = \App\Models\TitlePromotion::where('title', $data['title'])->first();
            
            if ($titlePromotion) {
                $data['title_promotion_id'] = $titlePromotion->id;
                $data['start_date'] = $titlePromotion->start_date;
                $data['end_date'] = $titlePromotion->end_date;
            }
        }

        $this->validatePromotionData($data, $id);

        DB::beginTransaction();
        
        try {
            $updateData = [
                'discount_type' => $data['discount_type'],
                'discount_value' => $data['discount_value'],
                'is_active' => $data['is_active'] ?? $promotion->is_active,
            ];
            
            // Mettre à jour title_promotion_id si fourni
            if (isset($data['title_promotion_id'])) {
                $updateData['title_promotion_id'] = $data['title_promotion_id'];
            }
            
            // Mettre à jour title si fourni
            if (isset($data['title'])) {
                $updateData['title'] = $data['title'];
            }
            
            // Mettre à jour les dates si fournies
            if (isset($data['start_date'])) {
                $updateData['start_date'] = $data['start_date'];
            }
            if (isset($data['end_date'])) {
                $updateData['end_date'] = $data['end_date'];
            }
            
            $promotion->update($updateData);

            // Mettre à jour les produits si fournis
            if (isset($data['product_ids'])) {
                $this->validateProductIds($data['product_ids'], $vendorId);
                $promotion->products()->sync($data['product_ids']);
            }

            DB::commit();
            return $promotion->load('products');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Supprimer une promotion
     */
    public function deletePromotion(int $id, int $vendorId): void
    {
        $promotion = $this->getPromotion($id, $vendorId);
        
        if (!$promotion) {
            throw new \Exception('Promotion non trouvée.');
        }

        $promotion->delete();
    }

    /**
     * Activer/Désactiver une promotion
     */
    public function toggleStatus(int $id, int $vendorId): Promotion
    {
        $promotion = $this->getPromotion($id, $vendorId);
        
        if (!$promotion) {
            throw new \Exception('Promotion non trouvée.');
        }

        $promotion->update([
            'is_active' => !$promotion->is_active
        ]);

        return $promotion->load('products');
    }

    /**
     * Valider les données de la promotion
     */
    private function validatePromotionData(array $data, ?int $id = null): void
    {
        $rules = [
            'title_promotion_id' => 'required|integer|exists:title_promotions,id',
            'title' => 'nullable|string|max:255', // Sera rempli automatiquement depuis title_promotions
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'start_date' => 'nullable|date', // Optionnel car récupéré depuis title_promotions
            'end_date' => 'nullable|date|after:start_date', // Optionnel car récupéré depuis title_promotions
            'is_active' => 'boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id'
        ];

        // Validation spéciale pour les pourcentages
        if (isset($data['discount_type']) && $data['discount_type'] === 'percentage') {
            $rules['discount_value'] .= '|max:100';
        }

        // Si start_date est fourni, end_date doit être après
        if (isset($data['start_date']) && isset($data['end_date'])) {
            $rules['end_date'] = 'required|date|after:start_date';
        }

        $validator = Validator::make($data, $rules);
        
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * Valider que les produits appartiennent au vendeur
     */
    private function validateProductIds(array $productIds, int $vendorId): void
    {
        $invalidProducts = \App\Models\Product::whereIn('id', $productIds)
            ->where('vendor_id', '!=', $vendorId)
            ->pluck('id')
            ->toArray();

        if (!empty($invalidProducts)) {
            throw ValidationException::withMessages([
                'product_ids' => 'Certains produits ne vous appartiennent pas: ' . implode(', ', $invalidProducts)
            ]);
        }
    }

    /**
     * Obtenir les statistiques des promotions
     */
    public function getPromotionStats(int $vendorId): array
    {
        $now = Carbon::now();
        
        $promotions = Promotion::forVendor($vendorId)->get();
        
        $total = $promotions->count();
        $active = 0;
        $inactive = 0;
        $upcoming = 0;
        $expired = 0;
        
        foreach ($promotions as $promotion) {
            // Compter les actives/inactives
            if ($promotion->is_active) {
                $active++;
            } else {
                $inactive++;
            }
            
            // Compter les à venir (pas encore commencées)
            if ($promotion->start_date && $promotion->start_date->isFuture()) {
                $upcoming++;
            }
            
            // Compter les expirées (date de fin passée)
            if ($promotion->end_date && $promotion->end_date->isPast()) {
                $expired++;
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'upcoming' => $upcoming,
            'expired' => $expired,
        ];
    }
}
