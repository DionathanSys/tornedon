<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\AccountPayable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountPayable extends Model
{
    protected $fillable = [
        'supplier_id',
        'manual_counterparty_name',
        'company_id',
        'fiscal_document_id',
        'bank_slip_number',
        'note_number',
        'sequence_number',
        'status',
        'due_date',
        'paid_date',
        'due_amount',
        'paid_amount',
        'document_number',
        'description',
        'is_effective',
        'auto_register_payment_on_due_date',
        'auto_payment_financial_account_id',
        'paid',
        'type',
        'payment_method',
    ];

    protected $casts = [
        'status' => Status::class,
        'payment_method' => PaymentMethod::class,
        'due_date' => 'date',
        'paid_date' => 'date',
        'due_amount' => MoneyCast::class,
        'paid_amount' => MoneyCast::class,
        'is_effective' => 'boolean',
        'auto_register_payment_on_due_date' => 'boolean',
        'paid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(AccountPayableInstallment::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            AccountPayableInstallmentPayment::class,
            AccountPayableInstallment::class,
            'account_payable_id',
            'account_payable_installment_id'
        );
    }

    public function purchaseClosing(): HasOne
    {
        return $this->hasOne(PurchaseClosing::class);
    }

    public function companyCardStatements(): HasMany
    {
        return $this->hasMany(CompanyCardStatement::class);
    }

    protected function counterpartyLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->supplier?->name
                ?? $this->manual_counterparty_name
                ?? 'Nao informado',
        );
    }
}
