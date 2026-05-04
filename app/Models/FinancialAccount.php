<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\FinancialAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'institution_name',
        'branch',
        'account_number',
        'pix_key',
        'opening_balance',
        'opened_at',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'type' => FinancialAccountType::class,
        'opening_balance' => MoneyCast::class,
        'opened_at' => 'date',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'current_balance',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function statementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    public function companyCreditCardsAsDefault(): HasMany
    {
        return $this->hasMany(CompanyCreditCard::class, 'default_financial_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getCurrentBalanceAttribute(): float
    {
        $movementBalance = (float) $this->cashMovements()
            ->get(['amount', 'direction'])
            ->sum(fn (CashMovement $movement) => $movement->signed_amount);

        return round((float) $this->opening_balance + $movementBalance, 2);
    }

    public function getDisplayNameAttribute(): string
    {
        $type = $this->type?->description();

        return $type ? "{$this->name} ({$type})" : $this->name;
    }

    public static function optionsForCompany(int $companyId): array
    {
        return static::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (self $account) => [$account->id => $account->display_name])
            ->toArray();
    }
}
