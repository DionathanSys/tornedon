<?php

namespace App\Models;

use App\Enum\Commitment\BillingStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommitmentBilling extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'customer_commitment_id', 'service_order_id', 'invoice_id', 'status', 'approval_snapshot', 'settings_snapshot', 'attempts', 'last_error', 'approved_by', 'approved_at', 'active_batch_id', 'execution_token', 'completed_step', 'processing_started_at'];

    protected $casts = ['status' => BillingStatus::class, 'approval_snapshot' => 'array', 'settings_snapshot' => 'array', 'approved_at' => 'datetime', 'completed_step' => BillingStatus::class, 'processing_started_at' => 'datetime'];

    protected function contextRelations(): array
    {
        return ['commitment', 'serviceOrder', 'invoice', 'activeBatch'];
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(CustomerCommitment::class, 'customer_commitment_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function activeBatch(): BelongsTo
    {
        return $this->belongsTo(CommitmentBillingBatch::class, 'active_batch_id');
    }

    public function latestBatchItem(): HasOne
    {
        return $this->hasOne(CommitmentBillingBatchItem::class)->latestOfMany();
    }

    public function isRunning(): bool
    {
        return $this->execution_token !== null && $this->active_batch_id !== null
            && CommitmentBillingBatchItem::forCustomer($this->company_id, $this->customer_id)
                ->where('commitment_billing_id', $this->id)
                ->where('commitment_billing_batch_id', $this->active_batch_id)
                ->where('execution_token', $this->execution_token)
                ->whereIn('status', ['queued', 'processing'])
                ->where('updated_at', '>', now()->subMinutes(10))
                ->exists();
    }

    public function canRequestBilling(): bool
    {
        if ($this->isRunning()) {
            return false;
        }
        if ($this->status !== BillingStatus::EMISSION_QUEUED) {
            return true;
        }

        return $this->invoice?->fiscalDocuments()->where('document_type', 'nfse')->where('nfse_status', 'rejected')->exists() ?? false;
    }
}
