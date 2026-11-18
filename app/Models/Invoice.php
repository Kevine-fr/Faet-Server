<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'user_id',
        'vendor_id',
        'invoice_number',
        'cloudinary_public_id',
        'cloudinary_url',
        'file_size',
        'total_amount',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'generated_at' => 'datetime',
        'deleted_at'   => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
