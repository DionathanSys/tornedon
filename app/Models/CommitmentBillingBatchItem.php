<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitmentBillingBatchItem extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'customer_commitment_id', 'commitment_billing_batch_id', 'commitment_billing_id', 'execution_token', 'status', 'last_error', 'finished_at'];

    protected $casts = ['finished_at' => 'datetime'];

    protected function contextRelations(): array
    {
        return ['batch', 'billing', 'commitment'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommitmentBillingBatch::class, 'commitment_billing_batch_id');
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(CommitmentBilling::class, 'commitment_billing_id');
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(CustomerCommitment::class, 'customer_commitment_id');
    }
}
