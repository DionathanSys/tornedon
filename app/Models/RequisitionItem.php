<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionItem extends Model
{

    protected $fillable = [
        'requisition_id',
        'product_id',
        'unit_of_measure',
        'quantity',
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
        'unit_price' => MoneyCast::class,
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
        return $this->belongsTo(ProductStock::class, 'product_id');
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
