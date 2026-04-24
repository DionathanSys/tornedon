<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\Quote\Status;
use App\Services\Quote\QuoteNumberGenerator;
use App\Services\Quote\States\QuoteState;
use App\Services\Quote\States\StateResolver;
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
        'gross_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'total_amount' => MoneyCast::class,
        'additional_info' => 'array',
    ];

    protected $appends = [
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
            get: fn() => $this->items->whereNotNull('service_id')->sum('total_amount'),
        );
    }

    protected function totalAmountProducts(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->items->whereNotNull('product_id')->sum('total_amount'),
        );
    }

    
}
