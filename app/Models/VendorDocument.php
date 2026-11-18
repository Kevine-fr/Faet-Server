<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'document_type',
        'document_path',
        'status'
    ];

    protected $casts = [
        'status' => 'string'
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
