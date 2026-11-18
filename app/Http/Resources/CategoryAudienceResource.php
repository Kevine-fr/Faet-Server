<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryAudienceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            // On renvoie le NOM du parent (pas l’ID)
            'parent'      => optional($this->whenLoaded('parent'))->name,
            'is_active'   => (bool) $this->is_active,
            'created_at'  => optional($this->created_at)?->toISOString(),
            // Audiences sous forme de liste de noms
            'audiences'   => $this->whenLoaded('audiences', fn () =>
                $this->audiences->pluck('name')->values()
            ),
        ];
    }
}
