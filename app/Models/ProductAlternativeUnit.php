<?php

namespace App\Models;

use App\Enum\Product\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAlternativeUnit extends Model
{
    protected $fillable = [
        'product_id',
        'unit',
        'conversion_factor',
    ];

    protected $casts = [
        'unit' => Unit::class,
        'conversion_factor' => 'decimal:8',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
