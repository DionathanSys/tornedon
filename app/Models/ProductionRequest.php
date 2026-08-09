<?php

namespace App\Models;

use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\ProductionRequest\Status;
use App\Services\ProductionRequest\ProductionRequestNumberGenerator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionRequest extends Model
{
    private ?array $resolvedItemsAmounts = null;

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if (blank($request->number)) {
                $request->number = ProductionRequestNumberGenerator::generate((int) $request->company_id);
            }
        });
    }

    protected $fillable = [
        'number',
        'company_id',
        'customer_id',
        'manual_counterparty_name',
        'status',
        'order_date',
        'closed_at',
        'delivered_at',
        'payment_method',
        'payment_condition',
        'financial_category_id',
        'account_receivable_id',
        'observations',
        'additional_info',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => Status::class,
        'order_date' => 'date',
        'closed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'payment_method' => PaymentMethod::class,
        'payment_condition' => PaymentCondition::class,
        'additional_info' => 'array',
    ];

    protected $appends = [
        'gross_amount',
        'discount_amount',
        'total_amount',
        'counterparty_label',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionRequestItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDelivered(): bool
    {
        return $this->status === Status::DELIVERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === Status::CANCELLED;
    }

    protected function grossAmount(): Attribute
    {
        return Attribute::make(get: fn (): float => $this->resolveItemsAmount('gross_amount'));
    }

    protected function discountAmount(): Attribute
    {
        return Attribute::make(get: fn (): float => $this->resolveItemsAmount('discount_amount'));
    }

    protected function totalAmount(): Attribute
    {
        return Attribute::make(get: fn (): float => $this->resolveItemsAmount('total_amount'));
    }

    protected function counterpartyLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->customer?->name
                ?? $this->manual_counterparty_name
                ?? 'Não informado',
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
                'gross_amount' => round((float) $this->items->sum(fn (ProductionRequestItem $item): float => (float) $item->gross_amount), 2),
                'discount_amount' => round((float) $this->items->sum(fn (ProductionRequestItem $item): float => (float) $item->discount_amount), 2),
                'total_amount' => round((float) $this->items->sum(fn (ProductionRequestItem $item): float => (float) $item->total_amount), 2),
            ];
        }

        $totals = $this->items()
            ->toBase()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as gross_amount, COALESCE(SUM(discount_amount), 0) as discount_amount, COALESCE(SUM(total_amount), 0) as total_amount')
            ->first();

        return $this->resolvedItemsAmounts = [
            'gross_amount' => round(((float) ($totals->gross_amount ?? 0)) / 100, 2),
            'discount_amount' => round(((float) ($totals->discount_amount ?? 0)) / 100, 2),
            'total_amount' => round(((float) ($totals->total_amount ?? 0)) / 100, 2),
        ];
    }
}
