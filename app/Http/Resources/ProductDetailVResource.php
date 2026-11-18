<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailVResource extends JsonResource
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
            'hash'           => $this->hash,
            'name'           => $this->name,
            'description'    => $this->description,
            'sku'            => $this->sku,
            'price'          => $this->price,
            'stock_quantity' => $this->stock_quantity,
            'is_active'      => $this->is_active,
            'is_blocked'     => $this->is_blocked,
            'category_id'   => $this->category_id,

            'category' => $this->category ? new CategoryResource($this->category) : [
                'id' => null,
                'name' => 'Aucune catégorie',
            ],

            'audiences' => $this->whenLoaded('audiences', function () {
                return $this->audiences->map(function ($audience) {
                    return [
                        'id' => $audience->id,
                        'name' => $audience->name,
                    ];
                });
            }, []),

            'images'   => ProductImageResource::collection($this->whenLoaded('images')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
    
}
