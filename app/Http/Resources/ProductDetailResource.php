<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            'sku'            => $this->sku,
            'price'          => $this->price,
            'stock_quantity' => $this->stock_quantity,
            'is_active'      => $this->is_active,
            'is_blocked'     => $this->is_blocked,

            'category' => $this->category ? new CategoryResource($this->category) : [
                'id' => null,
                'name' => 'Aucune catégorie',
            ],
            'vendor'   => new VendorResource($this->whenLoaded('vendor')),

            'images'   => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}