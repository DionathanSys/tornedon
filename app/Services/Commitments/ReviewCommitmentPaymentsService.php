<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\PaymentLineStatus;
use App\Models\CommitmentImportFile;
use App\Models\CommitmentPaymentLine;
use App\Models\Company;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewCommitmentPaymentsService
{
    public function ignore(int $companyId, int $lineId, int $userId, string $reason, bool $reopen = false): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|min:3|max:2000'])->validate();
        app(CommitmentPaymentContext::class)->transaction($companyId, $lineId, $userId, function (CommitmentPaymentLine $line) use ($reason, $reopen, $userId) {
            $this->editable($line);
            $line->update(['status' => $reopen ? PaymentLineStatus::UNMATCHED : PaymentLineStatus::IGNORED, 'review_reason' => trim($reason)]);
            app(AuditRecorder::class)->recordModelEvent($line, $reopen ? 'commitment.payment_reopened' : 'commitment.payment_ignored', $reopen ? 'Linha de pagamento reaberta.' : 'Linha de pagamento ignorada.', actorUserId: $userId, metadata: ['reason' => trim($reason)]);
        });
        app(MatchCommitmentPaymentsService::class)->refresh($companyId, $lineId, $userId);
    }

    public function correct(int $companyId, int $lineId, int $userId, array $data): void
    {
        $data = Validator::make($data, [
            'commitment_number' => ['required', 'string', 'regex:/^\d{1,40}$/D'], 'fiscal_year' => 'required|integer|between:1900,2200',
            'patrimony' => ['nullable', 'string', 'regex:/^\d{1,60}$/D'], 'fiscal_document_number' => ['required', 'string', 'regex:/^\d{1,60}$/D'],
            'fiscal_document_date' => 'required|date_format:Y-m-d', 'payment_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'amount' => 'required|numeric|min:0.01|decimal:0,2', 'reason' => 'required|string|min:3|max:2000', 'acknowledge_issues' => 'sometimes|boolean',
        ])->validate();
        app(CommitmentPaymentContext::class)->transaction($companyId, $lineId, $userId, function (CommitmentPaymentLine $line) use ($data, $userId) {
            $this->editable($line);
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($line);
            $changes = collect($data)->except(['reason', 'acknowledge_issues'])->all();
            $changes['commitment_number'] = CommitmentMatchValues::identifier($changes['commitment_number']);
            $changes['fiscal_document_number'] = CommitmentMatchValues::identifier($changes['fiscal_document_number']);
            $line->fill($changes)->fill(['customer_commitment_id' => null, 'status' => PaymentLineStatus::UNMATCHED, 'review_reason' => trim($data['reason']),
                'corrected_data' => ['values' => $changes, 'reason' => trim($data['reason']), 'user_id' => $userId, 'reviewed_at' => now()->toIso8601String(),
                    'acknowledged_issues' => ($data['acknowledge_issues'] ?? false) ? data_get($line->original_data, 'issues', []) : []]])->save();
            $audit->recordModelEvent($line, 'commitment.payment_corrected', 'Dados do pagamento conferidos com o relatório original.', before: $before, after: $audit->snapshot($line), actorUserId: $userId);
        });
        app(MatchCommitmentPaymentsService::class)->refresh($companyId, $lineId, $userId);
    }

    public function acknowledgeFile(int $companyId, int $fileId, int $userId, string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($companyId, $fileId, $userId, $reason) {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $file = CommitmentImportFile::forCompany($companyId)->where('type', 'payments')->lockForUpdate()->findOrFail($fileId);
            app(CommitmentImportAccess::class)->authorize($companyId, $file->customer_id, $userId);
            if (! in_array($file->status->value, ['processed', 'needs_review'], true)) {
                throw ValidationException::withMessages(['payment' => 'Conclua a leitura do relatório antes de conferir a integridade.']);
            }
            $file->update(['extracted_data' => [...$file->extracted_data, 'review' => ['acknowledged_issues' => CommitmentPaymentContext::reportIssues($file),
                'reason' => trim($reason), 'user_id' => $userId, 'reviewed_at' => now()->toIso8601String()]]]);
            app(AuditRecorder::class)->recordModelEvent($file, 'commitment.report_integrity_reviewed', 'Integridade do relatório conferida com motivo.', actorUserId: $userId, metadata: ['reason' => trim($reason)]);
        });
        app(MatchCommitmentPaymentsService::class)->refreshFile($companyId, $fileId, $userId);
    }

    private function editable(CommitmentPaymentLine $line): void
    {
        if ($line->commitment_receipt_id || $line->status === PaymentLineStatus::RECEIVED) {
            throw ValidationException::withMessages(['payment' => 'Esta linha já possui recebimento vinculado. Revise alterações ou estornos pelo fluxo financeiro.']);
        }
    }
}
