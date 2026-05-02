<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{

    protected $fillable = [
        'requisition_id',
        'product_id',
        'unit_of_measure',
        'quantity',
        'quantity_in_base_unit',
        'conversion_factor_snapshot',
        'unit_price',
        'unit_cost',
        'discount_percentage',
        'discount_amount',
        'stock_consumed',
        'stock_consumed_at',
        'commission_percentage',
        'observations',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_in_base_unit' => 'decimal:8',
        'conversion_factor_snapshot' => 'decimal:8',
        'unit_price' => MoneyCast::class,
        'gross_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'unit_cost' => MoneyCast::class,
        'discount_percentage' => 'decimal:2',
        'discount_amount' => MoneyCast::class,
        'stock_consumed' => 'boolean',
        'stock_consumed_at' => 'datetime',
        'commission_percentage' => 'decimal:2',
        'additional_info' => 'array',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class, 'product_id', 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes): float => round(
                array_key_exists('gross_amount', $attributes)
                    ? (float) $attributes['gross_amount']
                    : ((float) ($this->quantity ?? 0) * (float) ($this->unit_price ?? 0)),
                2
            ),
        );
    }

    public function resolvedBaseQuantity(): float
    {
        return (float) ($this->quantity_in_base_unit ?? $this->quantity ?? 0);
    }
}
