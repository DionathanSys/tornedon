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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServiceOrder extends Model
{
    use HasAttachments;

    private ?array $resolvedCommercialAmounts = null;

    protected static function booted(): void
    {
        static::deleting(function (self $serviceOrder): void {
            if ($serviceOrder->invoice_id || $serviceOrder->status === State::INVOICED) {
                throw new \RuntimeException('Não é possível excluir ordem de serviço que já foi faturada.');
            }

            $serviceOrder->attachments()->get()->each->delete();
        });
    }

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

    protected $appends = [
        'gross_amount',
        'discount_amount',
        'total_amount',
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

    public function requisition(): HasOne
    {
        return $this->hasOne(Requisition::class);
    }

    public function requisitionItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            RequisitionItem::class,
            Requisition::class,
            'service_order_id',
            'requisition_id',
            'id',
            'id',
        );
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

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes): float {
                $grossAmount = array_key_exists('computed_gross_amount', $attributes)
                    ? ((float) $attributes['computed_gross_amount']) / 100
                    : $this->resolveCommercialAmounts()['gross_amount'];

                return round($grossAmount + (float) ($this->travel_value ?? 0), 2);
            },
        );
    }

    protected function discountAmount(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes): float {
                if (array_key_exists('discount_amount', $attributes)) {
                    return round(((float) $attributes['discount_amount']) / 100, 2);
                }

                $discountAmount = array_key_exists('computed_discount_amount', $attributes)
                    ? ((float) $attributes['computed_discount_amount']) / 100
                    : $this->resolveCommercialAmounts()['discount_amount'];

                return round($discountAmount, 2);
            },
        );
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes): float {
                if (array_key_exists('total_amount', $attributes)) {
                    return round(((float) $attributes['total_amount']) / 100, 2);
                }

                $totalAmount = array_key_exists('computed_total_amount', $attributes)
                    ? ((float) $attributes['computed_total_amount']) / 100
                    : $this->resolveCommercialAmounts()['total_amount'];

                return round($totalAmount + (float) ($this->travel_value ?? 0), 2);
            },
        );
    }

    private function resolveCommercialAmounts(): array
    {
        if ($this->resolvedCommercialAmounts !== null) {
            return $this->resolvedCommercialAmounts;
        }

        if ($this->relationLoaded('items')) {
            return $this->resolvedCommercialAmounts = [
                'gross_amount' => round((float) $this->items->sum(fn (ServiceOrderItem $item): float => (float) $item->quantity * (float) $item->unit_price), 2),
                'discount_amount' => round((float) $this->items->sum('discount_amount'), 2),
                'total_amount' => round((float) $this->items->sum('total_amount'), 2),
            ];
        }

        $totals = $this->items()
            ->selectRaw('
                COALESCE(SUM(quantity * unit_price), 0) as gross_amount,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(total_amount), 0) as total_amount
            ')
            ->first();

        return $this->resolvedCommercialAmounts = [
            'gross_amount' => round(((float) ($totals->gross_amount ?? 0)) / 100, 2),
            'discount_amount' => round(((float) ($totals->discount_amount ?? 0)) / 100, 2),
            'total_amount' => round(((float) ($totals->total_amount ?? 0)) / 100, 2),
        ];
    }
}
