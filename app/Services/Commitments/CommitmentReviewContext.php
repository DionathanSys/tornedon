<?php

namespace App\Services\Commitments;

use App\Models\CommitmentImportFile;
use App\Models\Company;
use App\Models\CustomerCommitment;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitmentReviewContext
{
    public function transaction(int $companyId, int $commitmentId, int $userId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($companyId, $commitmentId, $userId, $callback) {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $candidate = CustomerCommitment::forCompany($companyId)->findOrFail($commitmentId);
            app(CommitmentImportAccess::class)->authorize($companyId, $candidate->customer_id, $userId);
            $file = CommitmentImportFile::forCustomer($companyId, $candidate->customer_id)->lockForUpdate()->findOrFail($candidate->commitment_import_file_id);
            $record = CustomerCommitment::forCompany($companyId)->lockForUpdate()->findOrFail($commitmentId);
            $record->setRelation('importFile', $file);
            if (! in_array($file->status->value, ['processed', 'needs_review'], true)) {
                throw ValidationException::withMessages(['commitment' => 'Conclua a leitura do PDF antes de conciliar ou revisar o empenho.']);
            }

            return $callback($record);
        });
    }

    public function assertEditable(CustomerCommitment $record): void
    {
        if ($record->billing()->exists() || $record->receipt()->exists() || $record->status->value === 'approved') {
            throw ValidationException::withMessages(['commitment' => 'Desvincule a OS antes de alterar os dados deste empenho.']);
        }
    }
}
