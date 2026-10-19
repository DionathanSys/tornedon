<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enum\Commitment\ImportType;
use App\Enum\Commitment\MatchStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class CustomerCommitment extends CommitmentRecord
{
    protected $fillable = ['company_id', 'customer_id', 'commitment_import_file_id', 'number', 'fiscal_year', 'issued_at', 'patrimony', 'object', 'amount', 'retained_amount', 'net_amount', 'page', 'original_data', 'corrected_data', 'review_reason', 'status', 'reviewed_by', 'reviewed_at', 'suggested_service_order_id', 'suggestion_count', 'match_diagnostics', 'matched_at'];

    protected $casts = ['fiscal_year' => 'integer', 'issued_at' => 'date', 'amount' => MoneyCast::class, 'retained_amount' => MoneyCast::class, 'net_amount' => MoneyCast::class, 'original_data' => 'array', 'corrected_data' => 'array', 'status' => MatchStatus::class, 'reviewed_at' => 'datetime', 'suggestion_count' => 'integer', 'match_diagnostics' => 'array', 'matched_at' => 'datetime'];

    protected function contextRelations(): array
    {
        return ['importFile', 'suggestedServiceOrder'];
    }

    protected function validateContext(): void
    {
        if ($this->importFile()->firstOrFail()->type !== ImportType::COMMITMENTS) {
            throw ValidationException::withMessages(['commitment_import_file_id' => 'O arquivo deve ser uma nota de empenho.']);
        }
    }

    public function importFile(): BelongsTo
    {
        return $this->belongsTo(CommitmentImportFile::class, 'commitment_import_file_id');
    }

    public function billing(): HasOne
    {
        return $this->hasOne(CommitmentBilling::class);
    }

    public function suggestedServiceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'suggested_service_order_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(CommitmentReceipt::class);
    }

    public function paymentLines(): HasMany
    {
        return $this->hasMany(CommitmentPaymentLine::class);
    }
}
