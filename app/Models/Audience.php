<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Audience extends Model
{
    //

    protected $fillable = [
        'name',
    ];

        // Une audience peut appartenir à plusieurs catégories via la table pivot
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_audiences', 'audiences_id', 'categories_id')
                    ->withTimestamps();
    }

    // Une audience peut appartenir à plusieurs produits via la table pivot
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_audiences', 'audiences_id', 'products_id')
                    ->withTimestamps();
    }
}
