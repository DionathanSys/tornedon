<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BankAccountConnection extends Model
{
    protected $hidden = ['credentials'];

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

    protected static function booted(): void
    {
        static::saving(function (self $connection): void {
            if (! $connection->exists || ! $connection->isDirty(['company_id', 'bank_id', 'environment', 'billing_provider_id'])) {
                return;
            }
            $used = fn ($query) => $query->whereNotNull('request_sent_at')->orWhereNotIn('status', ['pending_registration', 'registration_failed']);
            if ($connection->bankSlips()->where($used)->exists() || $connection->pixCharges()->where($used)->exists()) {
                throw ValidationException::withMessages([
                    'connection' => ['Empresa, banco, ambiente e provedor de uma conexão utilizada não podem ser alterados. Cadastre uma nova conexão.'],
                ]);
            }
        });
    }

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

    public function pixCharges(): HasMany
    {
        return $this->hasMany(PixCharge::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
