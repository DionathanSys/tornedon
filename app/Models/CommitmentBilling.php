<?php

namespace App\Models;

use App\Enum\Commitment\BillingStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitmentBilling extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'customer_commitment_id', 'service_order_id', 'invoice_id', 'status', 'approval_snapshot', 'settings_snapshot', 'attempts', 'last_error', 'approved_by', 'approved_at'];

    protected $casts = ['status' => BillingStatus::class, 'approval_snapshot' => 'array', 'settings_snapshot' => 'array', 'approved_at' => 'datetime'];

    protected function contextRelations(): array
    {
        return ['commitment', 'serviceOrder', 'invoice'];
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
}
