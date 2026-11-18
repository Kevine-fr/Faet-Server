<?php

namespace App\Traits;

use App\Services\SqidsService;

trait HasSqids
{
    /**
     * Accessoir pour obtenir le hash (généré à la volée)
     */
    public function getHashAttribute(): string
    {
        if (!$this->id) {
            return '';
        }
        return app(SqidsService::class)->encode($this->id);
    }

    /**
     * Scope pour trouver un modèle par hash
     */
    public function scopeFindByHash($query, string|int $identifier)
    {
        // Si c'est un entier, on cherche directement par ID
        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier);
        }

        // Sinon, on décode le hash
        $id = app(SqidsService::class)->decode($identifier);
        
        if ($id === null) {
            // On retourne une requête vide si le hash est invalide
            return $query->whereRaw('1 = 0');
        }

        return $query->where('id', $id);
    }

    /**
     * Résoudre le binding depuis la route (accepte ID ou hash)
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Si c'est un entier, on cherche par ID
        if (is_numeric($value)) {
            return $this->where('id', $value)->first();
        }

        // Sinon, on décode le hash et chercher par ID
        $id = app(SqidsService::class)->decode($value);
        if ($id === null) {
            return null;
        }

        return $this->where('id', $id)->first();
    }
}

