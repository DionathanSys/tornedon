<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyCreditCard extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'issuer',
        'issuer_partner_id',
        'last_four',
        'credit_limit',
        'closing_day',
        'due_day',
        'statement_cutoff_business_days',
        'default_financial_account_id',
        'active',
    ];

    protected $casts = [
        'credit_limit' => MoneyCast::class,
        'closing_day' => 'integer',
        'due_day' => 'integer',
        'statement_cutoff_business_days' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function issuerPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'issuer_partner_id');
    }

    public function defaultFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'default_financial_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CompanyCardTransaction::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(CompanyCardStatement::class);
    }
}
