<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReceivableInstallmentPayment extends Model
{
    protected $fillable = [
        'account_receivable_installment_id',
        'company_id',
        'payment_date',
        'amount',
        'interest_amount',
        'fine_amount',
        'discount_amount',
        'bank_account_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => MoneyCast::class,
        'interest_amount' => MoneyCast::class,
        'fine_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
    ];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivableInstallment::class, 'account_receivable_installment_id');
    }
}
