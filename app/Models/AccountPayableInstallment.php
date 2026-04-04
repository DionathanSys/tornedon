<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\AccountPayable\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountPayableInstallment extends Model
{
    protected $fillable = [
        'account_payable_id',
        'company_id',
        'sequence_number',
        'status',
        'due_date',
        'paid_date',
        'original_amount',
        'interest_amount',
        'fine_amount',
        'discount_amount',
        'due_amount',
        'paid_amount',
        'balance_amount',
        'bank_account_id',
        'financial_category_id',
        'cost_center_id',
        'notes',
    ];

    protected $casts = [
        'status' => Status::class,
        'due_date' => 'date',
        'paid_date' => 'date',
        'original_amount' => MoneyCast::class,
        'interest_amount' => MoneyCast::class,
        'fine_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'due_amount' => MoneyCast::class,
        'paid_amount' => MoneyCast::class,
        'balance_amount' => MoneyCast::class,
    ];

    public function accountPayable(): BelongsTo
    {
        return $this->belongsTo(AccountPayable::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountPayableInstallmentPayment::class, 'account_payable_installment_id');
    }
}
