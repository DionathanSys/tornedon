<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Product\Unit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderItem extends Model
{
    protected $fillable = [
        'service_order_id',
        'service_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount_percentage',
        'discount_amount',
        'observations',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity'             => 'decimal:3',
        'unit_price'           => MoneyCast::class,
        'unit_cost'            => MoneyCast::class,
        'discount_percentage'  => 'decimal:2',
        'discount_amount'      => MoneyCast::class,
        'gross_amount'         => MoneyCast::class,
        'subtotal'             => MoneyCast::class,
        'total_amount'         => MoneyCast::class,
        'additional_info'      => 'array',
    ];

    /* ==============================
     |  Relacionamentos
     |==============================*/

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
}
