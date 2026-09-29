<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccountConnection extends Model
{
    protected $fillable = [
        'company_id',
        'financial_account_id',
        'bank_id',
        'billing_provider_id',
        'environment',
        'credentials',
        'settings',
        'status',
        'last_success_at',
        'last_error_at',
        'last_error',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'last_success_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function billingProvider(): BelongsTo
    {
        return $this->belongsTo(BillingProvider::class);
    }

    public function bankSlips(): HasMany
    {
        return $this->hasMany(BankSlip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
