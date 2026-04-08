<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\CashMovementDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashMovement extends Model
{
    protected $fillable = [
        'company_id',
        'financial_account_id',
        'financial_category_id',
        'direction',
        'transaction_date',
        'amount',
        'description',
        'origin_type',
        'origin_id',
        'transfer_group_id',
        'notes',
        'reversed_at',
        'reversal_of_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'direction' => CashMovementDirection::class,
        'transaction_date' => 'date',
        'amount' => MoneyCast::class,
        'reversed_at' => 'datetime',
    ];

    protected $appends = [
        'signed_amount',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeUnreversed(Builder $query): Builder
    {
        return $query->whereNull('reversal_of_id');
    }

    public function getSignedAmountAttribute(): float
    {
        return round(((float) $this->amount) * ($this->direction?->multiplier() ?? 1), 2);
    }
}
