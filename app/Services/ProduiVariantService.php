<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

class ProduiVariantService
{
    //
    public function storeImagesForVariant(ProductVariant $variant, array $images)
    {
        foreach ($images as $image) {
            $path = $image->store('products/variants', 'public');
            $variant->product->images()->create([
                'name' => basename($path),
                'user_id' => $variant->product->user_id ?? null,
                'etat' => 1,
            ]);
        }
    }

    public function updateImagesForVariant(ProductVariant $variant, array $images)
    {
        // Supprime les anciennes si nécessaire
        foreach ($variant->product->images as $img) {
            Storage::disk('public')->delete('products/variants/' . $img->name);
            $img->delete();
        }

        // Puis ajoute les nouvelles
        $this->storeImagesForVariant($variant, $images);
    }

}
