<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankReconciliationGroup;
use App\Models\BankStatementLine;
use App\Services\Audit\AuditRecorder;
use App\Support\Financial\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Link an actual bank debit to the reversals of a returned receipt, without creating cash. */
class ReconcileRetainedReceiptReturnService
{
    public function reconcile(int $companyId, int $lineId, int $sourceGroupId, int $userId): BankReconciliationGroup
    {
        ReconcileReceiptWithRetainedExpensesService::authorize($companyId, $userId);

        return DB::transaction(function () use ($companyId, $lineId, $sourceGroupId, $userId) {
            $line = BankStatementLine::where('company_id', $companyId)->lockForUpdate()->findOrFail($lineId);
            $this->ensure($line->isOutflow() && $line->reconciliation_status?->canResolve() && ! $line->hasFinancialEffect(), 'Selecione uma saída bancária pendente.');
            $source = BankReconciliationGroup::where('company_id', $companyId)->where('operation_type', 'retained_receipt')->where('status', 'returned')->lockForUpdate()->findOrFail($sourceGroupId);
            $components = $source->items()->whereNotNull('returned_at')->with('cashMovement')->get();
            $this->ensure($components->isNotEmpty(), 'Operação sem devolução efetiva.');
            $movements = [];
            $net = 0;
            foreach ($components as $component) {
                $movement = $component->cashMovement->reversals()->where('company_id', $companyId)->lockForUpdate()->sole();
                $this->ensure((int) $movement->financial_account_id === (int) $line->financial_account_id && ! $movement->hasStatementLinks(), 'Movimento de devolução já conciliado ou de outra conta.');
                $this->ensure($movement->transaction_date->isSameDay($line->transaction_date), 'A data do extrato deve corresponder à data efetiva da devolução.');
                $net += Money::stored($movement) * $movement->direction->multiplier();
                $movements[] = [$component, $movement];
            }
            $this->ensure(-$net === Money::stored($line), 'O líquido da devolução não corresponde à saída do extrato.');
            $group = BankReconciliationGroup::create([
                'company_id' => $companyId, 'operation_type' => 'retained_receipt_return', 'status' => 'confirmed',
                'created_by' => $userId, 'confirmed_at' => now(), 'metadata' => ['source_group_id' => $sourceGroupId, 'net_cents' => -$net],
            ]);
            $targets = [];
            foreach ($movements as [$component, $movement]) {
                $group->items()->create([
                    'company_id' => $companyId, 'bank_statement_line_id' => $lineId, 'role' => 'return_link',
                    'receipt_item_id' => $component->id, 'cash_movement_id' => $movement->id, 'created_payment' => false,
                ]);
                $line->cashMovements()->attach($movement->id, ['amount' => Money::stored($movement)]);
                $targets[] = app(BankReconciliationQualityService::class)->target('cash_movement', $movement, Money::stored($movement));
            }
            $metadata = $line->metadata ?? [];
            if (isset($metadata['decision'])) {
                $metadata['decision_history'][] = $metadata['decision'];
            }
            $metadata['decision'] = ['type' => 'retained_receipt_return', 'group_id' => $group->id, 'resolved_by' => $userId, 'resolved_at' => now()->toDateTimeString()];
            $line->update(['bank_reconciliation_group_id' => $group->id, 'reconciliation_status' => 'reconciled', 'reconciled_at' => now(), 'metadata' => $metadata]);
            app(BankReconciliationQualityService::class)->confirm(collect([$line]), $targets, $userId, $group->id);
            app(AuditRecorder::class)->recordModelEvent($group, 'retained_receipt_return.created', 'Devolução efetiva conciliada pelo líquido', actorUserId: $userId);

            return $group;
        });
    }

    public function unlink(BankStatementLine $line, int $userId, string $reason): void
    {
        ReconcileReceiptWithRetainedExpensesService::authorize($line->company_id, $userId);
        DB::transaction(function () use ($line, $userId, $reason): void {
            $this->ensure(trim($reason) !== '', 'Informe o motivo da correção do vínculo.');
            $line = BankStatementLine::where('company_id', $line->company_id)->lockForUpdate()->findOrFail($line->id);
            $group = BankReconciliationGroup::where('company_id', $line->company_id)->where('operation_type', 'retained_receipt_return')->where('status', 'confirmed')->lockForUpdate()->findOrFail($line->bank_reconciliation_group_id);
            $line->cashMovements()->detach();
            $metadata = $line->metadata ?? [];
            $metadata['decision_history'][] = $metadata['decision'] ?? [];
            $metadata['decision'] = ['type' => 'reconciliation_reversed', 'group_id' => $group->id, 'reason' => $reason, 'resolved_by' => $userId];
            $line->update(['bank_reconciliation_group_id' => null, 'reconciliation_status' => 'pending', 'reconciled_at' => null, 'needs_review_at' => null, 'review_reason' => null, 'metadata' => $metadata]);
            // These movements predate this linking operation and must remain effective and reusable.
            $group->update(['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            app(BankReconciliationQualityService::class)->reverse($line, $reason, $group->id);
            app(AuditRecorder::class)->recordModelEvent($group, 'retained_receipt_return.unlinked', 'Vínculo da devolução desfeito; movimentos preservados', actorUserId: $userId, metadata: ['reason' => $reason]);
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['return' => $message]);
        }
    }
}
