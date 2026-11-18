<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'path'      => $this->path,
            'alt'       => $this->alt,
            'is_primary'=> $this->is_primary,
            'position'  => $this->position,
        ];
    }
}
