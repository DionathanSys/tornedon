<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\MatchStatus;
use App\Models\CustomerCommitment;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewCommitmentService
{
    public function correct(int $companyId, int $commitmentId, int $userId, array $data): CustomerCommitment
    {
        return app(CommitmentReviewContext::class)->transaction($companyId, $commitmentId, $userId, function (CustomerCommitment $record) use ($data, $userId) {
            app(CommitmentReviewContext::class)->assertEditable($record);
            if ($record->status === MatchStatus::IGNORED) {
                throw ValidationException::withMessages(['commitment' => 'Reabra o empenho antes de corrigir os dados.']);
            }
            $validated = Validator::make($data, [
                'number' => ['required', 'regex:/^\d{1,40}$/', Rule::unique('customer_commitments', 'number')->where('company_id', $record->company_id)->where('customer_id', $record->customer_id)->where('fiscal_year', $data['fiscal_year'] ?? null)->ignore($record->id)],
                'fiscal_year' => ['required', 'integer', 'between:1900,9999'],
                'issued_at' => ['required', 'date_format:Y-m-d'],
                'patrimony' => ['required', 'regex:/^\d{1,60}$/'],
                'object' => ['required', 'string', 'max:10000'],
                'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
                'retained_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'net_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
                'reason' => ['required', 'string', 'min:3', 'max:2000'],
                'acknowledge_issues' => ['sometimes', 'boolean'],
            ])->validate();
            $validated['number'] = CommitmentMatchValues::identifier($validated['number']);
            $duplicate = CustomerCommitment::forCustomer($record->company_id, $record->customer_id)->where('number', $validated['number'])->where('fiscal_year', $validated['fiscal_year'])->whereKeyNot($record->id)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['number' => 'Já existe um empenho com este número e exercício para o cliente.']);
            }
            $gross = CommitmentMatchValues::cents($validated['amount']);
            $retained = CommitmentMatchValues::cents($validated['retained_amount']);
            $net = CommitmentMatchValues::cents($validated['net_amount']);
            if ($gross - $retained !== $net) {
                throw ValidationException::withMessages(['net_amount' => 'O valor líquido deve corresponder ao bruto menos as retenções.']);
            }
            $issues = data_get($record->importFile->extracted_data, 'issues', []);
            $before = app(AuditRecorder::class)->snapshot($record);
            $correction = [
                'number' => $validated['number'], 'fiscal_year' => (int) $validated['fiscal_year'], 'issued_at' => $validated['issued_at'],
                'patrimony' => CommitmentMatchValues::identifier($validated['patrimony']), 'object' => $validated['object'],
                'amount_cents' => $gross, 'retained_amount_cents' => $retained, 'net_amount_cents' => $net,
                'acknowledged_issues' => ($validated['acknowledge_issues'] ?? false) ? $issues : data_get($record->corrected_data, 'acknowledged_issues', []),
            ];
            $record->update([
                'number' => $correction['number'], 'fiscal_year' => $correction['fiscal_year'], 'issued_at' => $correction['issued_at'],
                'patrimony' => $correction['patrimony'], 'object' => $correction['object'],
                'amount' => $gross / 100, 'retained_amount' => $retained / 100, 'net_amount' => $net / 100,
                'corrected_data' => $correction, 'review_reason' => $validated['reason'], 'reviewed_by' => $userId, 'reviewed_at' => now(),
            ]);
            app(AuditRecorder::class)->recordModelEvent($record, 'commitment.extraction_reviewed', 'Dados extraídos do empenho revisados.', before: $before, after: app(AuditRecorder::class)->snapshot($record), actorUserId: $userId);

            return app(SuggestCommitmentMatchesService::class)->refresh($record->company_id, $record->id, $userId);
        });
    }

    public function ignore(int $companyId, int $commitmentId, int $userId, string $reason): CustomerCommitment
    {
        return $this->changeIgnored($companyId, $commitmentId, $userId, $reason, true);
    }

    public function reopen(int $companyId, int $commitmentId, int $userId, string $reason): CustomerCommitment
    {
        return $this->changeIgnored($companyId, $commitmentId, $userId, $reason, false);
    }

    private function changeIgnored(int $companyId, int $commitmentId, int $userId, string $reason, bool $ignore): CustomerCommitment
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        return app(CommitmentReviewContext::class)->transaction($companyId, $commitmentId, $userId, function (CustomerCommitment $record) use ($reason, $ignore, $userId) {
            app(CommitmentReviewContext::class)->assertEditable($record);
            if (! $ignore && $record->status !== MatchStatus::IGNORED) {
                throw ValidationException::withMessages(['commitment' => 'Somente empenhos ignorados podem ser reabertos.']);
            }
            $record->update(['status' => $ignore ? MatchStatus::IGNORED : MatchStatus::UNMATCHED,
                'suggested_service_order_id' => null, 'suggestion_count' => 0, 'review_reason' => $reason,
                'reviewed_by' => $userId, 'reviewed_at' => now()]);
            app(AuditRecorder::class)->recordModelEvent($record, $ignore ? 'commitment.ignored' : 'commitment.reopened', $ignore ? 'Empenho ignorado.' : 'Empenho reaberto.', actorUserId: $userId, metadata: ['reason' => $reason]);

            return $ignore ? $record : app(SuggestCommitmentMatchesService::class)->refresh($record->company_id, $record->id, $userId);
        });
    }
}
