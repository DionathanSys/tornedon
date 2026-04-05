<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Services\ServiceOrder\StateResolver;
use App\Services\ServiceOrder\States\ServiceOrderState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServiceOrder extends Model
{
    use HasAttachments;

    protected static function booted(): void
    {
        static::deleting(function (self $serviceOrder): void {
            if ($serviceOrder->invoice_id || $serviceOrder->status === State::INVOICED) {
                throw new \RuntimeException('Não é possível excluir ordem de serviço que já foi faturada.');
            }

            $serviceOrder->attachments()->get()->each->delete();
        });
    }

    protected $appends = [
        'total_amount',
        'discount_amount',
    ];

    protected $fillable = [
        'number',
        'customer_id',
        'company_id',
        'quote_id',
        'order_date',
        'scheduled_date',
        'limit_date',
        'completion_date',
        'status',
        'priority',
        'type',
        'solution',
        'equipment_id',
        'location',
        'customer_observations',
        'technician_observations',
        'estimated_hours',
        'actual_hours',
        'value_km',
        'distance_km',
        'travel_value',
        'payment_method',
        'payment_condition',
        'technician_id',
        'supervisor_id',
        'salesperson_id',
        'warranty_expires_at',
        'requires_approval',
        'approved_by_customer',
        'approved_at',
        'customer_signature',
        'customer_signed_at',
        'customer_rating',
        'customer_feedback',
        'invoice_id',
        'items_received',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date'            => 'date',
        'scheduled_date'        => 'date',
        'limit_date'            => 'date',
        'completion_date'       => 'date',
        'status'                => State::class,
        'priority'              => Priority::class,
        'type'                  => Type::class,
        'payment_method'        => PaymentMethod::class,
        'payment_condition'     => PaymentCondition::class,
        'estimated_hours'       => 'decimal:2',
        'actual_hours'          => 'decimal:2',
        'value_km'              => MoneyCast::class,
        'distance_km'           => 'decimal:2',
        'travel_value'          => MoneyCast::class,
        'warranty_expires_at'   => 'date',
        'requires_approval'     => 'boolean',
        'approved_by_customer'  => 'boolean',
        'approved_at'           => 'datetime',
        'customer_signed_at'     => 'datetime',
        'customer_rating'       => 'decimal:1',
        'items_received'        => 'string',
        'additional_info'       => 'array',
    ];

    public function state(): ServiceOrderState
    {
        return StateResolver::resolve($this);
    }

    /* ==============================
     |  Relacionamentos
     |==============================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    public function remittanceAssets(): BelongsToMany
    {
        return $this->belongsToMany(
            RemittanceAsset::class,
            'service_order_received_assets'
        )
            ->withPivot(['quantity_allocated', 'notes'])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ==============================
     |  Computed Attributes
     |==============================*/

    /**
     * Total de descontos da OS: soma dos descontos dos itens.
     */
    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: fn(): float => round($this->sumItemsColumn('discount_amount'), 2)
        );
    }

    /**
     * Total da OS: soma dos totais dos itens + valor de deslocamento.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn(): float => round(
                $this->sumItemsColumn('total_amount')
                    + (float) $this->travel_value,
                2
            )
        );
    }

    private function sumItemsColumn(string $column): float
    {
        if ($this->relationLoaded('items')) {
            return (float) $this->items->sum(
                fn(ServiceOrderItem $item): float => (float) $item->{$column}
            );
        }

        return round(((float) $this->items()->sum($column)) / 100, 2);
    }
}
