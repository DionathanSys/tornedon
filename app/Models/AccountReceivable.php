<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AccountReceivable extends Model
{
    protected $fillable = [
        'customer_id',
        'manual_counterparty_name',
        'company_id',
        'invoice_id',
        'fiscal_document_id',
        'sequence_number',
        'status',
        'due_date',
        'paid_date',
        'due_amount',
        'paid_amount',
        'document_number',
        'description',
        'paid',
        'type',
        'payment_method',
        'card_payment_profile_id',
        'gross_amount',
        'card_fee_percent_snapshot',
        'card_fee_fixed_snapshot',
        'card_fee_amount',
        'net_amount',
        'payment_date',
        'settlement_days_snapshot',
        'expected_settlement_date',
        'card_rule_snapshot',
    ];

    protected $casts = [
        'status' => Status::class,
        'payment_method' => PaymentMethod::class,
        'due_date' => 'date',
        'paid_date' => 'date',
        'payment_date' => 'date',
        'expected_settlement_date' => 'date',
        'due_amount' => MoneyCast::class,
        'paid_amount' => MoneyCast::class,
        'gross_amount' => MoneyCast::class,
        'card_fee_percent_snapshot' => 'float',
        'card_fee_fixed_snapshot' => MoneyCast::class,
        'card_fee_amount' => MoneyCast::class,
        'net_amount' => MoneyCast::class,
        'settlement_days_snapshot' => 'integer',
        'card_rule_snapshot' => 'array',
        'paid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ==============================
     |  Relationships
     |==============================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function cardPaymentProfile(): BelongsTo
    {
        return $this->belongsTo(CardPaymentProfile::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(AccountReceivableInstallment::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            AccountReceivableInstallmentPayment::class,
            AccountReceivableInstallment::class,
            'account_receivable_id',
            'account_receivable_installment_id'
        );
    }

    protected function counterpartyLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->customer?->name
                ?? $this->manual_counterparty_name
                ?? 'Nao informado',
        );
    }
}
