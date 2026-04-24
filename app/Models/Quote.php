<?php

namespace App\Models;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Quote\Status;
use App\Services\Quote\QuoteNumberGenerator;
use App\Services\Quote\States\QuoteState;
use App\Services\Quote\States\StateResolver;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Quote extends Model //implements HasMedia
{
    // use InteractsWithMedia;

    private ?array $resolvedCommercialAmounts = null;

    protected $fillable = [
        'quote_number',
        'company_id',
        'customer_id',
        'description',
        'status',
        'valid_until',
        'observations',
        'customer_observations',
        'approved_at',
        'approved_by',
        'rejected_reason',
        'payment_method',
        'payment_condition',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'valid_until' => 'date',
        'approved_at' => 'datetime',
        'additional_info' => 'array',
    ];

    protected $appends = [
        'gross_amount',
        'discount_amount',
        'total_amount',
        'total_amount_services',
        'total_amount_products',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Quote $quote) {
            if (empty($quote->quote_number)) {
                $quote->quote_number = QuoteNumberGenerator::generate($quote->company_id);
            }
        });
    }

    /* ==============================
     |  Relationships
     |==============================*/

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function productionOrder(): HasOne
    {
        return $this->hasOne(ProductionOrder::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }    

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    /**
     * Retorna o objeto de estado atual do orçamento (State Pattern).
     */
    public function state(): QuoteState
    {
        return StateResolver::resolve($this);
    }

    /* ==============================
     |  Attributes
     |==============================*/

    protected function totalAmountServices(): Attribute
    {
        return Attribute::make(
            get: fn() => round(
                $this->relationLoaded('items')
                    ? (float) $this->items->whereNotNull('service_id')->sum('total_amount')
                    : ((float) $this->items()->whereNotNull('service_id')->sum('total_amount')) / 100,
                2
            ),
        );
    }

    protected function totalAmountProducts(): Attribute
    {
        return Attribute::make(
            get: fn() => round(
                $this->relationLoaded('items')
                    ? (float) $this->items->whereNotNull('product_id')->sum('total_amount')
                    : ((float) $this->items()->whereNotNull('product_id')->sum('total_amount')) / 100,
                2
            ),
        );
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes): float {
                if (array_key_exists('gross_amount', $attributes)) {
                    return round(((float) $attributes['gross_amount']) / 100, 2);
                }

                $grossAmount = array_key_exists('computed_gross_amount', $attributes)
                    ? ((float) $attributes['computed_gross_amount']) / 100
                    : $this->resolveCommercialAmounts()['gross_amount'];

                return round($grossAmount, 2);
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

                return round($totalAmount, 2);
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
                'gross_amount' => round((float) $this->items->sum(fn (QuoteItem $item): float => (float) $item->quantity * (float) $item->unit_price), 2),
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
