<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Quote\Destination;
use App\Enum\Quote\Status;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuoteItem extends Model
{
    protected $fillable = [
        'quote_id',
        'product_id',
        'service_id',
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
        'destination',
        'status',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => MoneyCast::class,
        'discount_percentage' => 'decimal:3',
        'discount_amount' => MoneyCast::class,
        'gross_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'technical_specifications' => 'array',
        'estimated_production_hours' => 'decimal:2',
        'material_cost' => MoneyCast::class,
        'labor_cost' => MoneyCast::class,
        'additional_info' => 'array',
        'destination' => Destination::class,
        'status' => Status::class,
    ];

    protected $appends = ['identifier', 'is_product'];

    /* ==============================
     |  Relationships
     |==============================*/

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productStock(): BelongsTo
    {
        return $this->belongsTo(ProductStock::class, 'product_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function productionOrderItem(): HasOne
    {
        return $this->hasOne(ProductionOrderItem::class);
    }

    /* ==============================
     |  Attributes
     |==============================*/

    public function identifier(): Attribute
    {
        if($this->isProduct) {
            return Attribute::make(
                get: fn() => 'PC - ' . ($this->product->name ?? 'Produto Desconecido'),
            );
        } else {
            return Attribute::make(
                get: fn() => 'MO - ' . ($this->service->name ?? 'Serviço Desconecido'),
            );
        }
    }

    public function codeItem(): Attribute
    {
        return Attribute::make(
            get: function() {
                if ($this->isProduct) {
                    return $this->product->product_code ?? '###';
                } else {
                    return $this->service->service_code ?? '###';
                }
            },
        );
    }

    public function isProduct(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->product_id !== null,
        );
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

    /* ==============================
     |  Helpers
     |==============================*/

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

    public function resolveDescription(): string
    {
        $description = trim((string) ($this->description ?? ''));

        if ($description !== '') {
            return $description;
        }

        $productName = trim((string) ($this->product?->name ?? ''));
        if ($productName !== '') {
            return $productName;
        }

        $serviceName = trim((string) ($this->service?->name ?? ''));
        if ($serviceName !== '') {
            return $serviceName;
        }

        return 'Item do orçamento';
    }
}
