<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingOption extends Model
{
    //
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}