<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRequestItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $product = $item->relationLoaded('product') ? $item->product : $item->product()->first();

            $item->unit_price ??= 0;
            $item->discount_percentage ??= 0;
            $item->discount_amount ??= 0;
            $item->sequence ??= 1;
            $item->unit_of_measure ??= $product?->unit?->value ?? 'UN';

            if (blank($item->description)) {
                $item->description = $product?->name;
            }
        });
    }

    protected $fillable = [
        'production_request_id',
        'product_id',
        'description',
        'unit_of_measure',
        'quantity',
        'unit_price',
        'discount_percentage',
        'discount_amount',
        'sequence',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => MoneyCast::class,
        'discount_percentage' => 'decimal:2',
        'discount_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
    ];

    public function productionRequest(): BelongsTo
    {
        return $this->belongsTo(ProductionRequest::class);
    }

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

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(((float) $this->quantity * (float) $this->unit_price), 2),
        );
    }
}
