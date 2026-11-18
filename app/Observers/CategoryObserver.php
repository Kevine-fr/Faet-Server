<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryObserver
{
    public function saved($category)
    {
        $this->clearCategoryCaches($category);
    }

    public function deleted($category)
    {
        $this->clearCategoryCaches($category);
    }

    private function clearCategoryCaches($category)
    {
        Cache::forget("category_with_children_{$category->id}");
        Cache::forget("category_descendants_{$category->id}_*");
        Cache::forget("home_categories_*");
        
        if ($category->parent_id) {
            Cache::forget("category_with_children_{$category->parent_id}");
            Cache::forget("category_descendants_{$category->parent_id}_*");
        }
    }
}
