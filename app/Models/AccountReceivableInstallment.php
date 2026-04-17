<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\AccountReceivable\Status;
use App\Models\Concerns\SkipsGeneratedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountReceivableInstallment extends Model
{
    use SkipsGeneratedColumns;

    protected $fillable = [
        'account_receivable_id',
        'company_id',
        'sequence_number',
        'status',
        'due_date',
        'received_date',
        'original_amount',
        'interest_amount',
        'fine_amount',
        'discount_amount',
        'due_amount',
        'received_amount',
        'balance_amount',
        'bank_account_id',
        'financial_category_id',
        'cost_center_id',
        'description',
        'notes',
    ];

    protected $casts = [
        'status' => Status::class,
        'due_date' => 'date',
        'received_date' => 'date',
        'original_amount' => MoneyCast::class,
        'interest_amount' => MoneyCast::class,
        'fine_amount' => MoneyCast::class,
        'discount_amount' => MoneyCast::class,
        'due_amount' => MoneyCast::class,
        'received_amount' => MoneyCast::class,
        'balance_amount' => MoneyCast::class,
    ];

    public function accountReceivable(): BelongsTo
    {
        return $this->belongsTo(AccountReceivable::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialCategory(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountReceivableInstallmentPayment::class, 'account_receivable_installment_id');
    }
}
