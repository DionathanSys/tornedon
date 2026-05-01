<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\StockMovement\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_stock_id',
        'product_id',
        'company_id',
        'type',
        'operational_unit',
        'operational_quantity',
        'base_unit',
        'base_quantity',
        'conversion_factor_snapshot',
        'quantity',
        'unit_price',
        'total_amount',
        'reason',
        'observations',
        'source_type',
        'source_id',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => Type::class,
        'operational_quantity' => 'decimal:3',
        'base_quantity' => 'decimal:3',
        'conversion_factor_snapshot' => 'decimal:8',
        'quantity' => 'decimal:3',
        'unit_price' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'additional_info' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function productStock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class);
    }

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

    /**
     * Entidade de origem que gerou esta movimentação (ex: Requisition, Quote, ServiceOrder).
     * Usa morph map registrado em AppServiceProvider para aliases curtos (ex: 'requisition').
     */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function resolvedBaseQuantity(): float
    {
        return (float) ($this->base_quantity ?? $this->quantity ?? 0);
    }

    public function resolvedOperationalQuantity(): float
    {
        return (float) ($this->operational_quantity ?? $this->quantity ?? 0);
    }
}
