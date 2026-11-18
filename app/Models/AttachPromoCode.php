<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachPromoCode extends Model
{
    protected $fillable = [
        'promo_code_id',
        'product_id',
        'final_price'
    ];

    protected $casts = [
        'final_price' => 'decimal:2'
    ];

    /**
     * Relation avec le code promo
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * Relation avec le produit
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
