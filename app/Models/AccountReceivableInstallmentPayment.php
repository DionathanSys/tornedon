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
        'origin',
        'bank_confirmed_at',
        'amount',
        'interest_amount',
        'fine_amount',
        'discount_amount',
        'bank_account_id',
        'financial_account_id',
        'bank_slip_id',
        'banking_event_id',
        'returned_amount',
        'bank_slip_event_id',
        'pix_charge_id',
        'description',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'bank_confirmed_at' => 'datetime',
        'amount' => MoneyCast::class,
        'returned_amount' => MoneyCast::class,
        'interest_amount' => MoneyCast::class,
        'fine_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
    ];

    public function installment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivableInstallment::class, 'account_receivable_installment_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function bankSlip(): BelongsTo
    {
        return $this->belongsTo(BankSlip::class);
    }

    public function bankSlipEvent(): BelongsTo
    {
        return $this->belongsTo(BankSlipEvent::class);
    }

    public function pixCharge(): BelongsTo
    {
        return $this->belongsTo(PixCharge::class);
    }
}
