<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Calculer le prix minimum des variantes si le prix du produit est null
        $displayPrice = $this->price;
        if ($displayPrice === null) {
            $minVariantPrice = $this->variants()
                ->whereNotNull('price')
                ->where('price', '>', 0)
                ->min('price');

            if ($minVariantPrice !== null) {
                $displayPrice = $minVariantPrice;
            }
        }

        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'sku'         => $this->sku,
            'description'  => $this->description,
            'price'        => $displayPrice,
            'stock_quantity'=> $this->stock_quantity,
            'is_active'    => (bool) $this->is_active,
            'category'     => $this->category ? [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ] : [
                'id'   => null,
                'name' => 'Aucune catégorie',
            ],
            'audiences'    => $this->audiences->map(function ($audience) {
                return [
                    'id'   => $audience->id,
                    'name' => $audience->name,
                ];
            }),
        ];
    }
}
