<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalDocument extends Model
{
    use HasAttachments;

    protected static function booted(): void
    {
        static::deleting(function (self $fiscalDocument): void {
            if ($fiscalDocument->isNfse() && $fiscalDocument->isNfseSent()) {
                throw new \RuntimeException('Não é possível excluir documento fiscal que já teve comunicação com a API da prefeitura.');
            }

            if ($fiscalDocument->isNfe() && $fiscalDocument->isNfeSent()) {
                throw new \RuntimeException('Não é possível excluir documento fiscal que já teve comunicação com a API fiscal/SEFAZ.');
            }
        });
    }

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
        'return_financial_data',
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
        'emission_requested_at',
        'emission_attempted_at',
        'emission_group_key',
        'nfse_model',
        'nfse_status',
        'nfse_payload',
        'nfse_protocol',
        'rps_number',
        'rps_series',
        'rps_type',
        'nfse_sequence_id',
        'fiscal_profile_id',
        'tax_regime_used',
    ];

    protected $casts = [
        'status'                    => Status::class,
        'document_type'             => DocumentModel::class,
        'operation_nature'          => OperationNature::class,
        'operation_type'            => OperationType::class,
        'issue_purpose'             => IssuePurpose::class,
        'buyer_presence_indicator'  => BuyerPresenceIndicator::class,
        'issued_at' => 'date',
        'movement_at' => 'date',
        'is_final_consumer' => 'boolean',
        'freight_data' => 'array',
        'payment_data' => 'array',
        'tax_data' => 'array',
        'return_financial_data' => 'array',
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
        'emission_requested_at' => 'datetime',
        'emission_attempted_at' => 'datetime',
        'nfse_status'     => NfeStatus::class,
        'nfse_payload'    => 'array',
        'return_financial_processed_at' => 'datetime',
        'return_stock_processed_at' => 'datetime',
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

    protected function itemsTotal(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                if (array_key_exists('items_total', $this->attributes)) {
                    return round((float) $this->attributes['items_total'] / 100, 2);
                }

                if ($this->relationLoaded('items')) {
                    return round($this->items->sum(fn (FiscalDocumentItem $item): float => (float) $item->total_price), 2);
                }

                return round((float) $this->items()->sum('total_price') / 100, 2);
            },
        );
    }

    public function remittanceAssets(): HasMany
    {
        return $this->hasMany(RemittanceAsset::class);
    }

    public function accountPayables(): HasMany
    {
        return $this->hasMany(AccountPayable::class);
    }

    public function accountReceivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class);
    }

    public function purchaseClosingLinks(): HasMany
    {
        return $this->hasMany(PurchaseClosingFiscalDocument::class);
    }

    public function purchaseClosings(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseClosing::class, 'purchase_closing_fiscal_documents')
            ->withPivot(['document_amount', 'discount_amount'])
            ->withTimestamps();
    }

    public function nfeSequence(): BelongsTo
    {
        return $this->belongsTo(NfeSequence::class);
    }

    public function nfseSequence(): BelongsTo
    {
        return $this->belongsTo(NfseSequence::class);
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function purchaseReturnCredits(): HasMany
    {
        return $this->hasMany(PurchaseReturnCredit::class, 'return_fiscal_document_id');
    }

    /* ==============================
     |  Helpers
     |==============================*/

    public function isNfe(): bool
    {
        return $this->document_type === DocumentModel::NFE;
    }

    public function isNfse(): bool
    {
        return $this->document_type === DocumentModel::NFSE;
    }

    public function isNfePending(): bool
    {
        return $this->nfe_status === NfeStatus::PENDING;
    }

    public function isNfeQueued(): bool
    {
        return $this->nfe_status === NfeStatus::QUEUED;
    }

    public function isNfeInProcessing(): bool
    {
        return $this->nfe_status === NfeStatus::IN_PROCESSING;
    }

    public function isNfeAuthorized(): bool
    {
        return $this->nfe_status === NfeStatus::AUTHORIZED;
    }

    public function isNfeRejected(): bool
    {
        return $this->nfe_status === NfeStatus::REJECTED;
    }

    public function isNfeCanceled(): bool
    {
        return $this->nfe_status === NfeStatus::CANCELED;
    }

    public function isNfeSent(): bool
    {
        return $this->nfe_status !== null
            && ! in_array($this->nfe_status, [NfeStatus::PENDING, NfeStatus::QUEUED], true);
    }

    public function blocksNfeResubmission(): bool
    {
        return in_array($this->nfe_status, [
            NfeStatus::QUEUED,
            NfeStatus::IN_PROCESSING,
            NfeStatus::AUTHORIZED,
            NfeStatus::CANCELED,
        ], true);
    }

    public function isNfsePending(): bool
    {
        return $this->nfse_status === NfeStatus::PENDING;
    }

    public function isNfseQueued(): bool
    {
        return $this->nfse_status === NfeStatus::QUEUED;
    }

    public function isNfseInProcessing(): bool
    {
        return $this->nfse_status === NfeStatus::IN_PROCESSING;
    }

    public function isNfseAuthorized(): bool
    {
        return $this->nfse_status === NfeStatus::AUTHORIZED;
    }

    public function isNfseRejected(): bool
    {
        return $this->nfse_status === NfeStatus::REJECTED;
    }

    public function isNfseCanceled(): bool
    {
        return $this->nfse_status === NfeStatus::CANCELED;
    }

    public function isNfseSent(): bool
    {
        return $this->nfse_status !== null
            && ! in_array($this->nfse_status, [NfeStatus::PENDING, NfeStatus::QUEUED], true);
    }

    public function isImportedFromDfe(): bool
    {
        return (bool) data_get($this->logs, 'imported_from_dfe', false);
    }

    public function isPurchaseReturn(): bool
    {
        return $this->isNfe()
            && $this->issue_purpose === IssuePurpose::DEVOLUCAO
            && $this->operation_nature === OperationNature::DEVOLUCAO_COMPRA;
    }

    public function hasReturnFinancialConfiguration(): bool
    {
        return is_array($this->return_financial_data)
            && filled(data_get($this->return_financial_data, 'mode'));
    }

    public function hasProcessedReturnFinancial(): bool
    {
        return $this->return_financial_processed_at !== null;
    }

    public function hasProcessedReturnStock(): bool
    {
        return $this->return_stock_processed_at !== null;
    }

    public function canEditItems(): bool
    {
        return ! $this->confirmed && ! $this->canceled;
    }

    public function canDeleteItems(): bool
    {
        return $this->canEditItems();
    }

    public function blocksNfseResubmission(): bool
    {
        return in_array($this->nfse_status, [
            NfeStatus::QUEUED,
            NfeStatus::IN_PROCESSING,
            NfeStatus::AUTHORIZED,
            NfeStatus::CANCELED,
        ], true);
    }

    /**
     * @return array<int,string>
     */
    public function allowedAttachmentTypes(): array
    {
        return [
            AttachmentType::FISCAL_DOCUMENT->value,
            AttachmentType::GENERIC->value,
        ];
    }
}
