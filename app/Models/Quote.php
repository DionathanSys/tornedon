<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Quote\Status;
use App\Services\Quote\QuoteNumberGenerator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Quote extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'quote_number',
        'company_id',
        'partner_id',
        'description',
        'status',
        'valid_until',
        'observations',
        'customer_observations',
        'approved_at',
        'approved_by',
        'rejected_reason',
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
        'total_amount',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
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

    /**
     * Calcula o valor total do orçamento somando todos os itens.
     */
    protected function totalAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->items->sum('total_amount'),
        );
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function canBeApproved(): bool
    {
        return $this->status === Status::SENT && !$this->isExpired();
    }

    public function canBeRejected(): bool
    {
        return $this->status === Status::SENT;
    }

    public function canBeConverted(): bool
    {
        return $this->status === Status::APPROVED && !$this->productionOrder()->exists();
    }
}
