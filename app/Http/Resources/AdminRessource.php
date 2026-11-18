<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRessource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
             'id'    => $this->id,
            'first_name'  => $this->first_name,
            'last_name'  => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'profile_picture'  => $this->profile_picture
                ? url($this->profile_picture)
                : null,
            'newsletter_enabled' => $this->newsletter_enabled,
            'notification_enabled' => $this->notification_enabled,
            'email_verified_at' => $this->email_verified_at,
            'status' => $this->status,

            // Adresse (stockée sur le modèle User)
            'street' => $this->street,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,

            // Ville vendeur (si disponible)
            'vendor_city' => optional($this->vendor)->ville,

            // Données vendeur (via la relation vendor)
            'vendor_phone' => optional($this->vendor)->phone,
            'vendor_street' => optional($this->vendor)->street,
            'vendor_postal_code' => optional($this->vendor)->postal_code,
            'vendor_city' => optional($this->vendor)->city,
            'vendor_country' => optional($this->vendor)->country,

            // Rôles utilisateur
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),

            // ID du vendeur (si disponible)
            'vendor_id' => optional($this->vendor)->id,

        ];
    }
}
