<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\CompanyCard\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyCardTransaction extends Model
{
    protected $fillable = [
        'company_id',
        'company_credit_card_id',
        'transaction_date',
        'posting_date',
        'description',
        'vendor_id',
        'amount',
        'installments',
        'current_installment',
        'installment_group_uuid',
        'parent_transaction_id',
        'category_id',
        'cost_center_id',
        'source_type',
        'source_id',
        'source_description',
        'statement_reference_month',
        'status',
        'meta',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'posting_date' => 'date',
        'amount' => MoneyCast::class,
        'installments' => 'integer',
        'current_installment' => 'integer',
        'statement_reference_month' => 'date',
        'status' => TransactionStatus::class,
        'meta' => 'array',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'vendor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'category_id');
    }

    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    public function childTransactions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id');
    }

    public function statementItems(): HasMany
    {
        return $this->hasMany(CompanyCardStatementItem::class);
    }

    public function statements(): BelongsToMany
    {
        return $this->belongsToMany(CompanyCardStatement::class, 'company_card_statement_items')
            ->withPivot('amount_allocated')
            ->withTimestamps();
    }
}
