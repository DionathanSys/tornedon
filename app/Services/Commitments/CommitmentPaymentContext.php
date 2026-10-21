<?php

namespace App\Services\Commitments;

use App\Models\CommitmentImportFile;
use App\Models\CommitmentPaymentLine;
use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitmentPaymentContext
{
    public function transaction(int $companyId, int $lineId, int $userId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($companyId, $lineId, $userId, $callback) {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $candidate = CommitmentPaymentLine::forCompany($companyId)->findOrFail($lineId);
            app(CommitmentImportAccess::class)->authorize($companyId, $candidate->customer_id, $userId);
            $file = CommitmentImportFile::forCustomer($companyId, $candidate->customer_id)->lockForUpdate()->findOrFail($candidate->commitment_import_file_id);
            if ($file->type->value !== 'payments' || ! in_array($file->status->value, ['processed', 'needs_review'], true)) {
                throw ValidationException::withMessages(['payment' => 'Conclua a leitura do relatório antes de conciliar os pagamentos.']);
            }
            $line = CommitmentPaymentLine::forCustomer($companyId, $candidate->customer_id)->lockForUpdate()->findOrFail($lineId);
            $line->setRelation('importFile', $file);

            return $callback($line);
        });
    }

    public static function reportIssues(CommitmentImportFile $file): array
    {
        // Earlier imports may still contain this obsolete company-name check.
        return array_values(array_filter(data_get($file->extracted_data, 'issues', []), fn ($issue) => $issue !== 'Nome do credor não corresponde à empresa selecionada.'));
    }
}
