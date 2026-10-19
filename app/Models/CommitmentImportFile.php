<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\ProcessingStatus;
use App\Enums\AttachmentType;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CommitmentImportFile extends CommitmentRecord
{
    use HasAttachments;

    protected $fillable = ['company_id', 'customer_id', 'commitment_import_id', 'type', 'sha256', 'original_name', 'status', 'parser_version', 'extracted_text', 'extracted_data', 'page_count', 'line_count', 'declared_total', 'extracted_total', 'attempts', 'last_error', 'processed_at'];

    protected $casts = ['type' => ImportType::class, 'status' => ProcessingStatus::class, 'extracted_data' => 'array', 'declared_total' => MoneyCast::class, 'extracted_total' => MoneyCast::class, 'processed_at' => 'datetime'];

    protected function contextRelations(): array
    {
        return ['import'];
    }

    protected function validateContext(): void
    {
        if ($this->import()->firstOrFail()->type !== $this->type) {
            throw ValidationException::withMessages(['type' => 'O tipo do arquivo deve corresponder ao lote.']);
        }
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(CommitmentImport::class, 'commitment_import_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(CustomerCommitment::class);
    }

    public function paymentLines(): HasMany
    {
        return $this->hasMany(CommitmentPaymentLine::class);
    }

    public function allowedAttachmentTypes(): array
    {
        return [$this->type === ImportType::PAYMENTS ? AttachmentType::COMMITMENT_PAYMENT_REPORT->value : AttachmentType::COMMITMENT_PDF->value];
    }
}
