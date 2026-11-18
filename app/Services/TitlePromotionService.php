<?php

namespace App\Services;

use App\Models\TitlePromotion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TitlePromotionService
{
    public function getAll(bool $withTrashed = false): Collection
    {
        if ($withTrashed) {
            return TitlePromotion::withTrashed()->orderBy('sort_order', 'asc')->get();
        }
        return TitlePromotion::orderBy('sort_order', 'asc')->get();
    }
    public function getPaginated(int $perPage = 20)
    {
        return TitlePromotion::orderByDesc('created_at')->paginate($perPage);
    }


    public function getById(int $id, bool $withTrashed = false): ?TitlePromotion
    {
        if ($withTrashed) {
            return TitlePromotion::withTrashed()->find($id);
        }
        return TitlePromotion::find($id);
    }

    public function create(array $data): TitlePromotion
    {
        try {
            $promotion = new TitlePromotion();
            $promotion->title = $data['title'];
            $promotion->description = $data['description'] ?? null;
            $promotion->sort_order = $data['sort_order'] ?? 0;

            // CONVERSION EXPLICITE DES DATES
            $promotion->start_date = Carbon::parse($data['start_date']);
            $promotion->end_date = isset($data['end_date']) ? Carbon::parse($data['end_date']) : null;

            if (!$promotion->save()) {
                throw new \Exception("Échec de la sauvegarde");
            }

            Log::info('Promotion créée avec succès', ['id' => $promotion->id]);

            return $promotion->fresh();

        } catch (\Exception $e) {
            Log::error('Erreur création promotion', [
                'message' => $e->getMessage(),
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function update(int $id, array $data): ?TitlePromotion
    {
        try {
            $promotion = $this->getById($id);

            if (!$promotion) {
                return null;
            }

            $promotion->title = $data['title'] ?? $promotion->title;
            $promotion->description = $data['description'] ?? $promotion->description;
            $promotion->sort_order = $data['sort_order'] ?? $promotion->sort_order;

            // CONVERSION EXPLICITE DES DATES
            if (isset($data['start_date'])) {
                $promotion->start_date = Carbon::parse($data['start_date']);
            }
            if (isset($data['end_date'])) {
                $promotion->end_date = Carbon::parse($data['end_date']);
            }

            $promotion->save();

            return $promotion->fresh();

        } catch (\Exception $e) {
            Log::error('Erreur mise à jour promotion', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        $promotion = $this->getById($id);

        if (!$promotion) {
            return false;
        }

        return (bool) $promotion->delete();
    }

    public function restore(int $id): ?TitlePromotion
    {
        $promotion = $this->getById($id, true);

        if (!$promotion || !$promotion->trashed()) {
            return null;
        }

        $promotion->restore();

        return $promotion->fresh();
    }
}