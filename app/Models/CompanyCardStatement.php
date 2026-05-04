<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\CompanyCard\StatementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyCardStatement extends Model
{
    protected $fillable = [
        'company_id',
        'company_credit_card_id',
        'reference_month',
        'period_start',
        'period_end',
        'cutoff_date',
        'closing_date',
        'due_date',
        'gross_total',
        'fees_total',
        'net_total',
        'paid_total',
        'balance_total',
        'status',
        'account_payable_id',
        'closed_at',
        'paid_at',
    ];

    protected $casts = [
        'reference_month' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'cutoff_date' => 'date',
        'closing_date' => 'date',
        'due_date' => 'date',
        'gross_total' => MoneyCast::class,
        'fees_total' => MoneyCast::class,
        'net_total' => MoneyCast::class,
        'paid_total' => MoneyCast::class,
        'balance_total' => MoneyCast::class,
        'status' => StatementStatus::class,
        'closed_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyCreditCard(): BelongsTo
    {
        return $this->belongsTo(CompanyCreditCard::class);
    }

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CompanyCardStatementItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CompanyCardStatementPayment::class);
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(CompanyCardTransaction::class, 'company_card_statement_items')
            ->withPivot('amount_allocated')
            ->withTimestamps();
    }
}
