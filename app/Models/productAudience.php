<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class productAudience extends Model
{
    protected $table = 'product_audiences';
    
    protected $fillable = [
        'products_id',
        'audiences_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'products_id');
    }

    public function audience()
    {
        return $this->belongsTo(Audience::class, 'audiences_id');
    }
}