<?php

namespace App\Models;

use App\Services\Product\ProductUnitConversionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $product = $item->relationLoaded('product') ? $item->product : ($item->product_id ? $item->product()->first() : null);

            $item->unit_price ??= 0;
            $item->discount_percentage ??= 0;
            $item->discount_amount ??= 0;
            $item->quantity_produced ??= 0;
            $item->quantity_approved ??= 0;
            $item->quantity_rejected ??= 0;
            $item->unit_of_measure ??= $product?->unit?->value ?? 'UN';

            if (blank($item->description)) {
                $item->description = $product?->name;
            }

            $quantity = (float) ($item->quantity ?? 0);
            $approvedQuantity = (float) ($item->quantity_approved ?? 0);

            if ($product) {
                $unit = (string) ($item->unit_of_measure ?? $product->unit?->value ?? 'UN');
                $conversionService = app(ProductUnitConversionService::class);

                $baseQuantity = $conversionService->convertToBase($product, $unit, $quantity);
                $approvedBaseQuantity = $conversionService->convertToBase($product, $unit, $approvedQuantity);

                $item->quantity_in_base_unit = round($baseQuantity->baseQuantity, 8);
                $item->quantity_approved_in_base_unit = round($approvedBaseQuantity->baseQuantity, 8);
                $item->conversion_factor_snapshot = $baseQuantity->factor;

                return;
            }

            $item->quantity_in_base_unit ??= $quantity;
            $item->quantity_approved_in_base_unit ??= $approvedQuantity;
            $item->conversion_factor_snapshot ??= 1;
        });
    }

    protected $fillable = [
        'production_order_id',
        'quote_item_id',
        'product_id',
        'description',
        'quantity',
        'quantity_in_base_unit',
        'unit_price',
        'discount_percentage',
        'discount_amount',
        'quantity_produced',
        'quantity_approved',
        'quantity_approved_in_base_unit',
        'quantity_rejected',
        'unit_of_measure',
        'conversion_factor_snapshot',
        'technical_specifications',
        'production_notes',
        'qc_notes',
        'actual_production_hours',
        'sequence',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_in_base_unit' => 'decimal:8',
        'unit_price' => 'decimal:4',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'quantity_produced' => 'decimal:3',
        'quantity_approved' => 'decimal:3',
        'quantity_approved_in_base_unit' => 'decimal:8',
        'quantity_rejected' => 'decimal:3',
        'conversion_factor_snapshot' => 'decimal:8',
        'technical_specifications' => 'array',
        'actual_production_hours' => 'decimal:2',
        'additional_info' => 'array',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isCompleted(): bool
    {
        return $this->quantity_produced >= $this->quantity;
    }

    public function getRemainingQuantity(): float
    {
        return max(0, $this->quantity - $this->quantity_produced);
    }

    public function getEfficiencyRate(): float
    {
        if ($this->quantity_produced == 0) {
            return 0;
        }

        return ($this->quantity_approved / $this->quantity_produced) * 100;
    }

    public function updateProductionQuantities(
        ?float $produced = null,
        ?float $approved = null,
        ?float $rejected = null
    ): void {
        if ($produced !== null) {
            $this->quantity_produced = $produced;
        }

        if ($approved !== null) {
            $this->quantity_approved = $approved;
        }

        if ($rejected !== null) {
            $this->quantity_rejected = $rejected;
        }

        // Validate that produced = approved + rejected
        if ($this->quantity_approved + $this->quantity_rejected > $this->quantity_produced) {
            throw new \InvalidArgumentException(
                'A soma de peças aprovadas e rejeitadas não pode exceder a quantidade produzida.'
            );
        }
    }

    public function resolvedBaseQuantity(): float
    {
        return $this->resolveBaseQuantityFor('quantity_in_base_unit', 'quantity');
    }

    public function resolvedApprovedBaseQuantity(): float
    {
        return $this->resolveBaseQuantityFor('quantity_approved_in_base_unit', 'quantity_approved');
    }

    private function resolveBaseQuantityFor(string $baseField, string $quantityField): float
    {
        if ($this->{$baseField} !== null) {
            return (float) $this->{$baseField};
        }

        $product = $this->relationLoaded('product') ? $this->product : $this->product()->first();

        if ($product) {
            return app(ProductUnitConversionService::class)
                ->convertToBase($product, (string) ($this->unit_of_measure ?? $product->unit?->value), (float) ($this->{$quantityField} ?? 0))
                ->baseQuantity;
        }

        return (float) ($this->{$quantityField} ?? 0);
    }
}
