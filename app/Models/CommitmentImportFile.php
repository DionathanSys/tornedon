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

    protected $fillable = ['company_id', 'customer_id', 'commitment_import_id', 'type', 'sha256', 'original_name', 'status', 'parser_version', 'extracted_text', 'extracted_data', 'page_count', 'line_count', 'declared_total', 'extracted_total', 'attempts', 'last_error', 'processed_at', 'processing_token', 'processing_started_at'];

    protected $casts = ['type' => ImportType::class, 'status' => ProcessingStatus::class, 'extracted_data' => 'array', 'declared_total' => MoneyCast::class, 'extracted_total' => MoneyCast::class, 'processed_at' => 'datetime', 'processing_started_at' => 'datetime'];

    public function canReprocess(): bool
    {
        if (($this->status === ProcessingStatus::QUEUED && $this->updated_at?->gt(now()->subMinutes(5)))
            || ($this->status === ProcessingStatus::PROCESSING && $this->processing_started_at?->gt(now()->subMinutes(5)))) {
            return false;
        }

        return ! $this->hasProtectedResults();
    }

    public function hasProtectedResults(): bool
    {
        if ($this->type === ImportType::PAYMENTS && filled(data_get($this->extracted_data, 'review'))) {
            return true;
        }

        return $this->commitments()->where(function ($query) {
            $query->whereHas('billing')->orWhereHas('receipt')->orWhereNotNull('reviewed_at')
                ->orWhereNotNull('corrected_data')->orWhereIn('status', ['approved', 'ignored']);
        })->exists() || $this->paymentLines()->where(function ($query) {
            $query->whereNotNull('customer_commitment_id')->orWhereNotNull('commitment_receipt_id')
                ->orWhere('status', '!=', 'unmatched')->orWhereNotNull('review_reason');
        })->exists();
    }

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
