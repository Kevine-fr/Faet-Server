<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class productSearch extends Model
{
    protected $fillable = [
        'product_id',
        'search_count',
        'last_searched_at'
    ];

    protected $casts = [
        'last_searched_at' => 'datetime'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
