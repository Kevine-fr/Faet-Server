<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    //

    protected $fillable = [
        'name',
        'description',
        'parent_id',
        'is_active',
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function audiences()
    {
        return $this->belongsToMany(Audience::class, 'category_audiences', 'categories_id', 'audiences_id')
                    ->withTimestamps();
    }

      /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeParentOnly($query, ?bool $onlyParents)
    {
        if ($onlyParents) {
            $query->whereNull('parent_id');
        }
        return $query;
    }

    public function scopeParentId($query, ?int $parentId)
    {
        if (!is_null($parentId)) {
            $query->where('parent_id', $parentId);
        }
        return $query;
    }

    public function scopeChildOf($query, ?int $parentId)
    {
        if (!is_null($parentId)) {
            $query->where('parent_id', $parentId);
        }
        return $query;
    }

    public function scopeIsActive($query, ?bool $isActive)
    {
        if (!is_null($isActive)) {
            $query->where('is_active', $isActive);
        }
        return $query;
    }


}