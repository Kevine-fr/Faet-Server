<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'user_id',
        'vendor_id',
        'promo_code_id',
        'subtotal',
        'discount_amount',
        'status',
        'payment_method',
        'payment_status',
        'total_amount',
        'seller_pickup_at',
        'actual_pickup_at',
        'cancelled_at',
        'cancelled_by',
        'cancelletion_reason',
        'cancelletion_reunded',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class, 'promo_code_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeStatus($query, ?string $status)
    {
        if ($status) {
            $query->where('status', $status);
        }
        return $query;
    }

    public function scopePaymentStatus($query, ?string $paymentStatus)
    {
        if ($paymentStatus) {
            $query->where('payment_status', $paymentStatus);
        }
        return $query;
    }

    public function scopePaymentMethod($query, ?string $paymentMethod)
    {
        if ($paymentMethod) {
            $query->where('payment_method', $paymentMethod);
        }
        return $query;
    }


}