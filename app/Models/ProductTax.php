<?php

namespace App\Models;

use App\Enum\Product\Origin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTax extends Model
{
    protected $fillable = [
        'product_id',
        'product_origin',
        'ncm_code',
        'cest_code',
        'icms',
        'ipi',
        'pis',
        'cofins',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'product_origin' => Origin::class,
        'icms' => 'array',
        'ipi' => 'array',
        'pis' => 'array',
        'cofins' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
