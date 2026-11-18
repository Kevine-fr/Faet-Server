<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'sku'           => $this->sku,
            'external_id'   => $this->external_id,
            'price'         => $this->price,
            'stock_quantity'=> $this->stock_quantity,
            'size'          => $this->size,
            'color'         => $this->color,
            'weight'        => $this->weight,
            'attributes'    => $this->attributes,
            'is_active'     => $this->is_active,
        ];
    }
}
