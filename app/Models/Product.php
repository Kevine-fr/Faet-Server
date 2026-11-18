<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Category;
use App\Traits\HasSqids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use SoftDeletes, Searchable, HasFactory, HasSqids;

    /**
     * Ajouter automatiquement le hash aux réponses JSON
     */
    protected $appends = ['hash'];

    protected $fillable = [
        'vendor_id',
        'category_id',
        'name',
        'description',
        'product_image',
        'sku',
        'price',
        'stock_quantity',
        'is_active',
        'is_blocked',
        'external_id',
        'external_data',
        'last_sync',
        'stock',
        'status',
    ];

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'category_name' => $this->category?->name,
            'category_id' => $this->category_id,
            'vendor_id' => $this->vendor_id,
            'stock_quantity' => $this->stock_quantity,
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            'is_blocked' => $this->is_blocked,
            'created_at' => $this->created_at?->timestamp,
            // Ajouter les SKUs des variants pour la recherche
            'variant_skus' => $this->variants->pluck('sku')->filter()->values()->toArray(),
            'has_variants' => $this->variants->count() > 0,
            'min_price' => $this->getMinPrice(),
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->is_active && !$this->is_blocked;
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'products_index';
    }

    /**
     * Modify the query used to retrieve models when making all searchable.
     */
    protected function makeAllSearchableUsing($query)
    {
        return $query->with(['category', 'variants']);
    }

    /**
     * Get the minimum price (from variants or product price)
     */
    public function getMinPrice(): float
    {
        $variantMinPrice = $this->variants()
            ->where('is_active', true)
            ->whereNotNull('price')
            ->min('price');

        return (float) ($variantMinPrice ?? $this->price ?? 0);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function searchStats()
    {
        return $this->hasOne(ProductSearch::class);
    }

    /**
     * Relation many-to-many avec les catégories via la table product_categories
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'product_categories')
                    ->withTimestamps();
    }


    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Relation many-to-many avec les codes promo via la table attach_promo_codes
     */
    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class, 'attach_promo_codes', 'product_id', 'promo_code_id')
                    ->withPivot('final_price')
                    ->withTimestamps();
    }

    /**
     * Relation vers les promotions
     */
    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(
            Promotion::class,
            'promotion_product',
            'product_id',
            'promotion_id'
        )->withTimestamps();
    }

    public function audiences()
    {
        return $this->belongsToMany(Audience::class, 'product_audiences', 'products_id', 'audiences_id')
                    ->withTimestamps();
    }


    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */


    public function scopeCategoryId($query, ?int $categoryId)
    {
        if (!is_null($categoryId)) {
            $query->where('category_id', $categoryId);
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