<?php

namespace App\Models;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Requisition\Status;
use App\Services\Requisition\States\RequisitionState;
use App\Services\Requisition\States\StateResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Requisition extends Model
{
    private ?array $resolvedItemsAmounts = null;

    protected static function booted(): void
    {
        static::deleting(function (self $requisition): void {
            if ($requisition->invoice_id || $requisition->status === Status::INVOICED) {
                throw new \RuntimeException('Não é possível excluir requisição que já gerou fatura.');
            }
        });
    }

    protected $fillable = [
        'number',
        'customer_id',
        'company_id',
        'service_order_id',
        'quote_id',
        'sale_date',
        'status',
        'payment_method',
        'payment_condition',
        'observations',
        'delivery_address',
        'delivery_date',
        'salesperson_id',
        'invoice_id',
        'invoiced_at',
        'equipment_id',
        'stock_consumed',
        'stock_reserved',
        'additional_info',
        'production_order_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'payment_method' => PaymentMethod::class,
        'payment_condition' => PaymentCondition::class,
        'sale_date' => 'date',
        'delivery_date' => 'date',
        'invoiced_at' => 'datetime',
        'stock_consumed' => 'boolean',
        'stock_reserved' => 'boolean',
        'additional_info' => 'array',
    ];

    protected $appends = [
        'gross_amount',
        'discount_amount',
        'total_amount',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source', 'source_type', 'source_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
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
     * Retorna o objeto de estado atual da requisição (State Pattern).
     */
    public function state(): RequisitionState
    {
        return StateResolver::resolve($this);
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('gross_amount'),
        );
    }

    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('discount_amount'),
        );
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->resolveItemsAmount('total_amount'),
        );
    }

    private function resolveItemsAmount(string $column): float
    {
        return round($this->resolveItemsAmounts()[$column] ?? 0.0, 2);
    }

    private function resolveItemsAmounts(): array
    {
        if ($this->resolvedItemsAmounts !== null) {
            return $this->resolvedItemsAmounts;
        }

        if ($this->relationLoaded('items')) {
            return $this->resolvedItemsAmounts = [
                'gross_amount' => round((float) $this->items->sum('gross_amount'), 2),
                'discount_amount' => round((float) $this->items->sum('discount_amount'), 2),
                'total_amount' => round((float) $this->items->sum('total_amount'), 2),
            ];
        }

        $totals = $this->items()
            ->selectRaw('
                COALESCE(SUM(gross_amount), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        return $this->resolvedItemsAmounts = [
            'gross_amount' => round(((float) ($totals->gross_amount ?? 0)) / 100, 2),
            'discount_amount' => round(((float) ($totals->discount_amount ?? 0)) / 100, 2),
            'total_amount' => round(((float) ($totals->total_amount ?? 0)) / 100, 2),
        ];
    }
}
