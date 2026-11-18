<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductObserver
{
    public function saved($product)
    {
        $this->clearRelatedCache($product);
    }

    public function deleted($product)
    {
        $this->clearRelatedCache($product);
    }

    private function clearRelatedCache($product)
    {
        Cache::forget("category_colors_{$product->category_id}");
        Cache::forget("category_sizes_{$product->category_id}");
        Cache::forget("category_price_range_{$product->category_id}");
        Cache::forget("category_ids_{$product->category_id}");
        
        // Si le produit a une catégorie parente
        if ($product->category && $product->category->parent_id) {
            Cache::forget("category_colors_{$product->category->parent_id}");
            Cache::forget("category_sizes_{$product->category->parent_id}");
            Cache::forget("category_price_range_{$product->category->parent_id}");
        }
    }
}
