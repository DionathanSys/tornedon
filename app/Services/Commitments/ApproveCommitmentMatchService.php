<?php

namespace App\Services\Commitments;

use App\Enum\Commitment\BillingStatus;
use App\Enum\Commitment\MatchStatus;
use App\Models\CommitmentBilling;
use App\Models\CommitmentBillingBatchItem;
use App\Models\CustomerCommitment;
use App\Models\ServiceOrder;
use App\Services\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;

class ApproveCommitmentMatchService
{
    public function approve(int $companyId, int $commitmentId, int $serviceOrderId, int $userId): CommitmentBilling
    {
        return app(CommitmentReviewContext::class)->transaction($companyId, $commitmentId, $userId, function (CustomerCommitment $record) use ($serviceOrderId, $userId) {
            $existing = $record->billing()->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->service_order_id === $serviceOrderId) {
                    return $existing;
                }
                throw ValidationException::withMessages(['service_order_id' => 'Este empenho já possui uma OS aprovada. Desvincule antes de escolher outra.']);
            }
            $order = ServiceOrder::query()->where('company_id', $record->company_id)->where('customer_id', $record->customer_id)->lockForUpdate()->findOrFail($serviceOrderId);
            // Lock the rows that compose the value; always reload amounts before approval.
            $order->equipment()->lockForUpdate()->first();
            $order->items()->lockForUpdate()->get();
            $request = $order->requisition()->lockForUpdate()->first();
            $request?->items()->lockForUpdate()->get();
            $engine = app(CommitmentMatchEngine::class);
            $result = $engine->analyze($record);
            if (! in_array($serviceOrderId, $result['eligible_ids'], true)) {
                $candidate = collect($result['candidates'])->firstWhere('service_order_id', $serviceOrderId);
                throw ValidationException::withMessages(['service_order_id' => $candidate['reason'] ?? $result['reason']]);
            }
            $snapshot = $engine->candidate($record, $order);
            if (! $snapshot['eligible']) {
                throw ValidationException::withMessages(['service_order_id' => $snapshot['reason']]);
            }
            $billing = CommitmentBilling::create([
                'company_id' => $record->company_id, 'customer_id' => $record->customer_id,
                'customer_commitment_id' => $record->id, 'service_order_id' => $order->id,
                'status' => BillingStatus::PENDING, 'approved_by' => $userId, 'approved_at' => now(),
                'approval_snapshot' => ['commitment_number' => $record->number, 'fiscal_year' => $record->fiscal_year,
                    'patrimony' => $record->patrimony, 'commitment_amount_cents' => (int) $record->getRawOriginal('amount'),
                    'selected_order' => $snapshot, 'oldest_eligible_order_id' => $result['eligible_ids'][0],
                    'review_reason' => $record->review_reason],
            ]);
            $record->update(['status' => MatchStatus::APPROVED, 'reviewed_by' => $userId, 'reviewed_at' => now(),
                'suggested_service_order_id' => $order->id, 'suggestion_count' => count($result['eligible_ids']),
                'match_diagnostics' => $result, 'matched_at' => now()]);
            app(AuditRecorder::class)->recordModelEvent($record, 'commitment.match_approved', 'Vínculo entre empenho e OS aprovado.', actorUserId: $userId, metadata: ['service_order_id' => $order->id, 'billing_id' => $billing->id]);

            return $billing;
        });
    }

    public function unlink(int $companyId, int $commitmentId, int $userId, string $reason): CustomerCommitment
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo da desvinculação.']);
        }

        return app(CommitmentReviewContext::class)->transaction($companyId, $commitmentId, $userId, function (CustomerCommitment $record) use ($userId, $reason) {
            $billing = $record->billing()->lockForUpdate()->firstOrFail();
            $order = $billing->serviceOrder()->lockForUpdate()->firstOrFail();
            if ($billing->invoice_id || $billing->isRunning() || ! in_array($billing->status, [BillingStatus::PENDING, BillingStatus::FAILED], true) || $order->invoice_id || $order->status->value === 'faturada' || $record->receipt()->exists()) {
                throw ValidationException::withMessages(['commitment' => 'O vínculo já iniciou faturamento ou recebimento e não pode ser removido por esta ação.']);
            }
            app(AuditRecorder::class)->recordModelEvent($record, 'commitment.match_unlinked', 'OS desvinculada do empenho.', actorUserId: $userId, metadata: ['reason' => trim($reason), 'billing_snapshot' => $billing->attributesToArray()]);
            CommitmentBillingBatchItem::forCustomer($record->company_id, $record->customer_id)->where('commitment_billing_id', $billing->id)
                ->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'last_error' => 'Vínculo removido antes do faturamento.', 'finished_at' => now()]);
            $billing->delete();
            $record->update(['status' => MatchStatus::UNMATCHED, 'suggested_service_order_id' => null,
                'suggestion_count' => 0, 'review_reason' => $reason, 'reviewed_by' => $userId, 'reviewed_at' => now()]);

            return app(SuggestCommitmentMatchesService::class)->refresh($record->company_id, $record->id, $userId);
        });
    }
}
