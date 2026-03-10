<?php

namespace App\Models;

use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDocument extends Model
{
    protected $fillable = [
        'customer_id',
        'company_id',
        'invoice_id',
        'status',
        'issued_at',
        'movement_at',
        'document_type',
        'document_key',
        'document_number',
        'document_series',
        'operation_type',
        'operation_nature',
        'issue_purpose',
        'is_final_consumer',
        'buyer_presence_indicator',
        'tax_observations',
        'additional_tax_information',
        'taxpayer_observations',
        'additional_taxpayer_information',
        'additional_purchase_information',
        'freight_data',
        'payment_data',
        'tax_data',
        'pending',
        'confirmed',
        'canceled',
        'created_by',
        'updated_by',
        'confirmed_by',
        'canceled_by',
        'confirmed_at',
        'canceled_at',
        'errors_messages',
        'logs',
        'nfe_status',
        'nfe_ambiente',
        'nfe_protocolo',
        'nfe_payload',
        'nfe_sequence_id',
        'fiscal_profile_id',
        'tax_regime_used',
    ];

    protected $casts = [
        'status' => Status::class,
        'issued_at' => 'date',
        'movement_at' => 'date',
        'operation_type' => 'integer',
        'is_final_consumer' => 'boolean',
        'buyer_presence_indicator' => 'boolean',
        'freight_data' => 'array',
        'payment_data' => 'array',
        'tax_data' => 'array',
        'pending' => 'boolean',
        'confirmed' => 'boolean',
        'canceled' => 'boolean',
        'confirmed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'errors_messages' => 'array',
        'logs'            => 'array',
        'nfe_status'      => NfeStatus::class,
        'nfe_payload'     => 'array',
        'nfe_ambiente'    => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FiscalDocumentItem::class);
    }

    public function accountPayables(): HasMany
    {
        return $this->hasMany(AccountPayable::class);
    }

    public function nfeSequence(): BelongsTo
    {
        return $this->belongsTo(NfeSequence::class);
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    /* ==============================
     |  Helpers
     |==============================*/

    public function isPending(): bool
    {
        return $this->nfe_status === NfeStatus::PENDING;
    }

    public function isInProcessing(): bool
    {
        return $this->nfe_status === NfeStatus::IN_PROCESSING;
    }

    public function isAuthorized(): bool
    {
        return $this->nfe_status === NfeStatus::AUTHORIZED;
    }

    public function isRejected(): bool
    {
        return $this->nfe_status === NfeStatus::REJECTED;
    }

    public function isCanceled(): bool
    {
        return $this->nfe_status === NfeStatus::CANCELED;
    }

    public function nfeSent(): bool
    {
        return $this->nfe_status !== null
            && $this->nfe_status !== NfeStatus::PENDING;
    }
}
