<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayableInstallmentPayment extends Model
{
    protected $fillable = [
        'account_payable_installment_id',
        'company_id',
        'payment_date',
        'amount',
        'interest_amount',
        'fine_amount',
        'discount_amount',
        'bank_account_id',
        'financial_account_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'float',
        'interest_amount' => 'float',
        'fine_amount' => 'float',
        'discount_amount' => 'float',
    ];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AccountPayableInstallment::class, 'account_payable_installment_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}
