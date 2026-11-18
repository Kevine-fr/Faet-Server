<?php

namespace App\Services;

use App\Models\Audience;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AudienceService
{
    //

    /**
     * Créer une nouvelle audience
     */
    public function create(array $data): Audience
    {
        return Audience::create($data);
    }

    /**
     * Mettre à jour une audience
     */
    public function update(int $id, array $data): Audience
    {
        $audience = Audience::findOrFail($id);
        $audience->update($data);
        return $audience;
    }

    /**
     * Supprimer une audience
     */
    public function delete(int $id): bool
    {
        $audience = Audience::findOrFail($id);
        return $audience->delete();
    }

    /**
     * Récupérer une audience par ID
     */
    public function findById(int $id): Audience
    {
        return Audience::findOrFail($id);
    }
}
