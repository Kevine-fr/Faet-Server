<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id'    => $this->id,
            'first_name'  => $this->first_name,
            'last_name'  => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_fixed' => $this->phone_fixed,
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

            // Rôles utilisateur
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),

            // Informations client (exposées si relation chargée)
            'customer' => $this->whenLoaded('customer', function () {
                return $this->customer ? [
                    'birthdate' => $this->customer->birthdate,
                    'gender_preference' => $this->customer->gender_preference,
                    'rue' => $this->customer->rue,
                    'code_postal' => $this->customer->code_postal,
                    'ville' => $this->customer->ville,

                    // Masquer la carte : garder seulement les 4 derniers chiffres
                    'numero_carte' => $this->customer->numero_carte
                        ? '**** **** **** ' . substr($this->customer->numero_carte, -4)
                        : null,

                    // Masquer la date d'expiration (sauf mois/année)
                    'expiration' => $this->customer->expiration ?? null,

                    // Ne jamais retourner le CVC pour des raisons de sécurité
                    'cvc' => null,

                    // Titulaire non masqué (pas critique)
                    'titulaire' => $this->customer->titulaire,
                ] : null;
            }),

            // Informations vendeur (exposées si relation chargée)
            'vendor' => $this->whenLoaded('vendor', function () {
                return $this->vendor ? [
                    'id' => $this->vendor->id,
                    'name_store' => $this->vendor->name_store,
                    'description' => $this->vendor->description,
                    'website_url' => $this->vendor->website_url,
                    'rue' => $this->vendor->rue,
                    'code_postal' => $this->vendor->code_postal,
                    'ville' => $this->vendor->ville,
                    'store_location' => $this->vendor->store_location,
                ] : null;
            }),
        ];
    }
}
