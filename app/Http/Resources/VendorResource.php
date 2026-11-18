<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                => $this->id,
            // 'company_name'      => $this->company_name,
            'siret'             => $this->siret,
            'website_url'       => $this->website_url,
            'ecommerce_platform'=> $this->ecommerce_platform,
            'name_store'        => $this->name_store,
            'subscription_type' => $this->subscription_type,
            'subscription_area' => $this->subscription_area,
            'is_synchronized'   => $this->is_synchronized,
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}