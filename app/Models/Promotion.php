<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;
use App\Models\TitlePromotion;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'title_promotion_id',
        'title',
        'discount_type',
        'discount_value',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Relation avec le vendeur
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Relation avec la title_promotion
     */
    public function titlePromotion(): BelongsTo
    {
        return $this->belongsTo(TitlePromotion::class, 'title_promotion_id');
    }

    /**
     * Relation many-to-many avec les produits
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_product')
                    ->withTimestamps();
    }

    /**
     * Vérifier si la promotion est valide (active et dans la période)
     */
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Calculer le prix après réduction
     */
    public function calculateDiscountedPrice(float $originalPrice): float
    {
        if (!$this->isValid()) {
            return $originalPrice;
        }

        if ($this->discount_type === 'percentage') {
            $discount = ($originalPrice * $this->discount_value) / 100;
            return max(0, $originalPrice - $discount);
        }

        // Type fixed
        return max(0, $originalPrice - $this->discount_value);
    }

    /**
     * Scope pour les promotions actives
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pour les promotions d'un vendeur
     */
    public function scopeForVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    /**
     * Scope pour les promotions valides (dans la période de validité)
     */
    public function scopeValid($query)
    {
        $now = Carbon::now();
        return $query->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where('end_date', '>=', $now);
    }
}
