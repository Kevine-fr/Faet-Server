<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class categoryAudience extends Model
{
    //

    protected $fillable = [
        'categories_id',
        'audiences_id',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'categories_id');
    }

    public function audience()
    {
        return $this->belongsTo(Audience::class, 'audiences_id');
    }

}
