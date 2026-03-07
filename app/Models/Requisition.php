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

    /* ==============================
     |  Computed Attributes
     |==============================*/

    /**
     * Total de descontos da requisição: soma dos descontos dos itens.
     */
    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                $this->items->sum(fn ($item) => (float) $item->discount_amount),
                2
            )
        );
    }

    /**
     * Total da requisição: soma dos totais dos itens.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                $this->items->sum(fn ($item) => (float) $item->total_amount),
                2
            )
        );
    }
}
