<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id',
        'product_id',
        'description',
        'quantity',
        'unit_of_measure',
        'unit_price',
        'discount_percentage',
        'discount_amount',
        'technical_specifications',
        'estimated_production_hours',
        'material_cost',
        'labor_cost',
        'sequence',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => MoneyCast::class,
        'discount_percentage' => 'decimal:2',
        'discount_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'technical_specifications' => 'array',
        'estimated_production_hours' => 'decimal:2',
        'material_cost' => MoneyCast::class,
        'labor_cost' => MoneyCast::class,
        'additional_info' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function productionOrderItem(): HasOne
    {
        return $this->hasOne(ProductionOrderItem::class);
    }

    /**
     * Calcula o total do item.
     * Nota: total_amount é uma coluna virtual no banco, mas este método
     * pode ser útil para cálculos antes de salvar.
     */
    public function calculateTotal(): float
    {
        $subtotal = $this->quantity * $this->unit_price;
        $discount = $this->discount_amount ?? ($subtotal * $this->discount_percentage / 100);
        return $subtotal - $discount;
    }

    public function getFormattedSpecifications(): string
    {
        if (!$this->technical_specifications) {
            return '';
        }

        $specs = [];
        foreach ($this->technical_specifications as $key => $value) {
            $specs[] = ucfirst($key) . ': ' . $value;
        }

        return implode(' | ', $specs);
    }
}
