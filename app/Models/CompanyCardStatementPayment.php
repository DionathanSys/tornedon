<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCardStatementPayment extends Model
{
    protected $fillable = [
        'company_id',
        'company_card_statement_id',
        'account_payable_installment_payment_id',
        'payment_date',
        'amount',
        'financial_account_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => MoneyCast::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CompanyCardStatement::class, 'company_card_statement_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function accountPayableInstallmentPayment(): BelongsTo
    {
        return $this->belongsTo(AccountPayableInstallmentPayment::class, 'account_payable_installment_payment_id');
    }
}
