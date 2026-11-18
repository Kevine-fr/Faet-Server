<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class ProductVariant extends Model
{
    use SoftDeletes, Searchable;

    protected $fillable = [
        'product_id',
        'external_id',
        'sku',
        'price',
        'stock_quantity',
        'size',
        'color',
        'weight',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'weight' => 'decimal:2'
    ];

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->sku,
            'size' => $this->size,
            'color' => $this->color,
            'price' => (float) $this->price,
            'stock_quantity' => $this->stock_quantity,
            'is_active' => $this->is_active,
            'category_name' => $this->product?->category?->name,
            'category_id' => $this->product?->category_id,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_active && 
               $this->product?->is_active && 
               !$this->product?->is_blocked;
    }

    public function searchableAs(): string
    {
        return 'product_variants_index';
    }

    protected function makeAllSearchableUsing($query)
    {
        return $query->with(['product.category', 'product.images']);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}