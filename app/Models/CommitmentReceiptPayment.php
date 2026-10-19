<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CommitmentReceiptPayment extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'commitment_receipt_id', 'payment_id', 'payment_snapshot'];

    protected $casts = ['payment_snapshot' => 'array'];

    protected function contextRelations(): array
    {
        return ['receipt', 'payment'];
    }

    protected function validateContext(): void
    {
        if ($this->payment_id) {
            $invoiceId = $this->payment()->firstOrFail()->installment?->accountReceivable?->invoice_id;
            if (! $invoiceId || (int) $invoiceId !== (int) $this->receipt()->firstOrFail()->invoice_id) {
                throw ValidationException::withMessages(['payment_id' => 'O pagamento deve pertencer à fatura do empenho.']);
            }
        }
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CommitmentReceipt::class, 'commitment_receipt_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AccountReceivableInstallmentPayment::class, 'payment_id');
    }
}
