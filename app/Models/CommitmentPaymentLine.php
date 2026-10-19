<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\PaymentLineStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CommitmentPaymentLine extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'commitment_import_file_id', 'position', 'page_start', 'page_end', 'commitment_number', 'fiscal_year', 'patrimony', 'fiscal_document_number', 'fiscal_document_date', 'payment_date', 'amount', 'object', 'original_data', 'status', 'review_reason', 'customer_commitment_id', 'commitment_receipt_id'];

    protected $casts = ['fiscal_document_date' => 'date', 'payment_date' => 'date', 'amount' => MoneyCast::class, 'original_data' => 'array', 'status' => PaymentLineStatus::class];

    protected function contextRelations(): array
    {
        return ['importFile', 'commitment', 'receipt'];
    }

    protected function validateContext(): void
    {
        if ($this->importFile()->firstOrFail()->type !== ImportType::PAYMENTS) {
            throw ValidationException::withMessages(['commitment_import_file_id' => 'O arquivo deve ser um relatório de pagamentos.']);
        }

        if ($this->commitment_receipt_id && (int) $this->receipt()->firstOrFail()->customer_commitment_id !== (int) $this->customer_commitment_id) {
            throw ValidationException::withMessages(['commitment_receipt_id' => 'O recebimento deve corresponder ao empenho da linha.']);
        }
    }

    public function importFile(): BelongsTo
    {
        return $this->belongsTo(CommitmentImportFile::class, 'commitment_import_file_id');
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(CustomerCommitment::class, 'customer_commitment_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CommitmentReceipt::class, 'commitment_receipt_id');
    }
}
