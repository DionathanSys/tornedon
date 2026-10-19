<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Commitment\ReceiptStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitmentReceipt extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'customer_commitment_id', 'invoice_id', 'financial_account_id', 'idempotency_key', 'payment_date', 'amount', 'status', 'created_by'];

    protected $casts = ['payment_date' => 'date', 'amount' => MoneyCast::class, 'status' => ReceiptStatus::class];

    protected function contextRelations(): array
    {
        return ['commitment', 'invoice', 'financialAccount'];
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(CustomerCommitment::class, 'customer_commitment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function paymentLinks(): HasMany
    {
        return $this->hasMany(CommitmentReceiptPayment::class);
    }

    public function reportLines(): HasMany
    {
        return $this->hasMany(CommitmentPaymentLine::class);
    }
}
