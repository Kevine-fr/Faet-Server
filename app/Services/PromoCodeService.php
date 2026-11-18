<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class PromoCodeService
{
    /**
     * Récupérer tous les codes promo d'un vendeur
     */
    public function getVendorPromoCodes(int $vendorId): Collection
    {
        return PromoCode::forVendor($vendorId)
            ->with(['products:id,name,sku'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Récupérer un code promo par ID
     */
    public function getPromoCode(int $id, int $vendorId): ?PromoCode
    {
        return PromoCode::forVendor($vendorId)
            ->with(['products:id,name,sku'])
            ->find($id);
    }

    /**
     * Créer un nouveau code promo
     */
    public function createPromoCode(array $data, int $vendorId): PromoCode
    {
        // Convertir le code en majuscules avant la validation
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $this->validatePromoCodeData($data);

        // Vérifier que le code est unique
        if (PromoCode::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Ce code promo existe déjà.'
            ]);
        }

        DB::beginTransaction();

        try {
            $promoCode = PromoCode::create([
                'vendor_id' => $vendorId,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'value' => $data['value'],
                'min_order_amount' => $data['min_order_amount'] ?? null,
                'max_uses' => $data['max_uses'] ?? null,
                'status' => $data['status'] ?? 'active',
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Attacher les produits
            if (!empty($data['product_ids'])) {
                $promoCode->products()->attach($data['product_ids']);
            }

            DB::commit();
            return $promoCode->load('products');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Mettre à jour un code promo
     */
    public function updatePromoCode(int $id, array $data, int $vendorId): PromoCode
    {
        $promoCode = $this->getPromoCode($id, $vendorId);

        if (!$promoCode) {
            throw new \Exception('Code promo non trouvé.');
        }

        // Convertir le code en majuscules avant la validation
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        $this->validatePromoCodeData($data, $id);

        // Vérifier que le code est unique (sauf pour ce code promo)
        if (PromoCode::where('code', $data['code'])->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Ce code promo existe déjà.'
            ]);
        }

        DB::beginTransaction();

        try {
            $promoCode->update([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'value' => $data['value'],
                'min_order_amount' => $data['min_order_amount'] ?? null,
                'max_uses' => $data['max_uses'] ?? null,
                'status' => $data['status'] ?? 'active',
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Mettre à jour les produits
            if (isset($data['product_ids'])) {
                $promoCode->products()->sync($data['product_ids']);
            }

            DB::commit();
            return $promoCode->load('products');

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Supprimer un code promo
     */
    public function deletePromoCode(int $id, int $vendorId): bool
    {
        $promoCode = $this->getPromoCode($id, $vendorId);

        if (!$promoCode) {
            throw new \Exception('Code promo non trouvé.');
        }

        // Permettre la suppression même si le code a été utilisé
        // (on peut vouloir supprimer un code promo pour des raisons administratives)

        return $promoCode->delete();
    }

    /**
     * Changer le statut d'un code promo
     */
    public function toggleStatus(int $id, int $vendorId): PromoCode
    {
        $promoCode = $this->getPromoCode($id, $vendorId);

        if (!$promoCode) {
            throw new \Exception('Code promo non trouvé.');
        }

        // Mettre à jour is_active
        $newActiveStatus = !$promoCode->is_active;
        $promoCode->update(['is_active' => $newActiveStatus]);

        // Mettre à jour le statut en fonction de is_active
        if (!$newActiveStatus) {
            // Si le code est désactivé, le statut devient "inactive"
            $promoCode->update(['status' => 'inactive']);
        } else {
            // Si le code est réactivé, recalculer le statut automatiquement
            $promoCode->recalculateStatusOnReactivation();
        }

        return $promoCode->fresh();
    }

    /**
     * Mettre à jour automatiquement les statuts
     */
    public function updateAllStatuses(): void
    {
        PromoCode::chunk(100, function ($promoCodes) {
            foreach ($promoCodes as $promoCode) {
                $promoCode->updateStatus();
            }
        });
    }

    /**
     * Récupérer les produits disponibles pour un vendeur
     */
    public function getAvailableProducts(int $vendorId): Collection
    {
        return Product::where('vendor_id', $vendorId)
            ->where('is_active', true)
            ->select('id', 'name', 'sku')
            ->orderBy('name')
            ->get();
    }

    /**
     * Valider les données du code promo
     */
    private function validatePromoCodeData(array $data, ?int $id = null): void
    {
        $rules = [
            'code' => 'required|string|max:50|regex:/^[A-Z0-9]+$/',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'status' => 'required|in:active,upcoming,expired,inactive',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after:valid_from',
            'is_active' => 'boolean',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id'
        ];

        // Validation spéciale pour les pourcentages
        if (isset($data['type']) && $data['type'] === 'percentage') {
            $rules['value'] .= '|max:100';
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * Générer un code promo unique
     */
    public function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid()), 0, 8));
        } while (PromoCode::where('code', $code)->exists());

        return $code;
    }

    /**
     * Obtenir les statistiques des codes promo
     */
    public function getPromoCodeStats(int $vendorId): array
    {
        $stats = PromoCode::forVendor($vendorId)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = "upcoming" THEN 1 ELSE 0 END) as upcoming_count,
                SUM(CASE WHEN status = "expired" THEN 1 ELSE 0 END) as expired_count,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive_count,
                SUM(used_count) as total_uses
            ')
            ->first();

        return [
            'total' => $stats->total ?? 0,
            'active' => $stats->active_count ?? 0,
            'upcoming' => $stats->upcoming_count ?? 0,
            'expired' => $stats->expired_count ?? 0,
            'inactive' => $stats->inactive_count ?? 0,
            'total_uses' => $stats->total_uses ?? 0,
        ];
    }

    /**
     * Retourne la liste paginée des codes promos.
     */
    public function getAllPaginated(int $perPage = 20)
    {
        return PromoCode::query()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
    /**
     * show vendeur
     */

    public function findById($id)
    {
        return PromoCode::with(['vendor', 'products', 'orders'])
            ->findOrFail($id);
    }
}