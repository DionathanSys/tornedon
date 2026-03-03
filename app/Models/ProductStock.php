<?php

namespace App\Models;

use App\Enum\StockMovement\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductStock extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'quantity_available',
        'quantity_reserved',
        'quantity_minimum',
        'quantity_maximum',
        'average_cost',
        'last_cost',
        'last_sale_price',
        'last_movement_date',
        'last_movement_type',
        'is_active',
        'allow_negative',
        'additional_info',
        'created_by',
        'updated_by',
        'company_id',
    ];

    protected $casts = [
        'quantity_available' => 'decimal:3',
        'quantity_reserved' => 'decimal:3',
        'quantity_minimum' => 'decimal:3',
        'quantity_maximum' => 'decimal:3',
        'average_cost' => 'decimal:4',
        'last_cost' => 'decimal:4',
        'last_sale_price' => 'decimal:4',
        'last_movement_date' => 'date',
        'last_movement_type' => Type::class,
        'is_active' => 'boolean',
        'allow_negative' => 'boolean',
        'additional_info' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
