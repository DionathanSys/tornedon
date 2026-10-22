<?php

namespace App\Services\Financial\BankStatement;

use App\Enum\Financial\BankStatementLineStatus;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReconcileBankStatementGroupService
{
    /** Each item has an id and an amount in reais. Existing movements are linked in full. */
    public function reconcile(int $companyId, array $lineIds, string $type, array $items, ?int $userId, ?string $exceptionReason = null): void
    {
        DB::transaction(function () use ($companyId, $lineIds, $type, $items, $userId, $exceptionReason) {
            $lineIds = array_unique($lineIds);
            $lines = BankStatementLine::query()->where('company_id', $companyId)
                ->whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get();
            $this->ensure($lines->isNotEmpty() && $lines->count() === count($lineIds), 'Selecione linhas válidas da empresa atual.');
            $first = $lines->first();
            foreach ($lines as $line) {
                $this->ensure($line->reconciliation_status?->canResolve() === true && ! $line->hasFinancialEffect(), 'Todas as linhas devem estar pendentes e sem vínculo financeiro.');
                $this->ensure($line->financial_account_id === $first->financial_account_id && $line->direction() === $first->direction() && $line->direction() !== null, 'Selecione linhas da mesma conta bancária e da mesma direção.');
            }
            $this->ensure(in_array($type, ['cash_movement', 'receivable', 'payable'], true), 'Tipo de conciliação inválido.');
            $this->ensure($type === 'cash_movement' || ($type === 'receivable' ? $first->isInflow() : $first->isOutflow()), 'O tipo de conta não corresponde à direção do extrato.');
            $this->ensure($items !== [] && count(array_unique(array_column($items, 'id'))) === count($items), 'Selecione itens distintos para conciliar.');
            $total = $lines->sum(fn ($line) => $this->cents($line->amount));
            $prepared = [];
            // Lock targets in a deterministic order before registering any financial effects.
            usort($items, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            foreach ($items as $item) {
                $model = match ($type) {
                    'receivable' => AccountReceivableInstallment::class,
                    'payable' => AccountPayableInstallment::class,
                    default => CashMovement::class,
                };
                $target = $model::query()->where('company_id', $companyId)->lockForUpdate()->find($item['id']);
                $this->ensure($target !== null, 'Item não encontrado na empresa atual.');
                $amount = $type === 'cash_movement' ? $this->cents($target->amount) : $this->cents($item['amount'] ?? 0);
                $this->ensure($amount > 0, 'Informe um valor positivo para cada parcela.');
                if ($type === 'cash_movement') {
                    $this->ensure($target->financial_account_id === $first->financial_account_id && $target->direction === $first->direction(), 'Movimento de outra conta bancária ou direção.');
                    $this->ensure(! $target->reversed_at && ! $target->reversal_of_id && ! $target->hasStatementLinks(), 'Movimento estornado ou já conciliado.');
                    $days = max(0, (int) ($first->financialAccount?->reconciliation_date_tolerance_days ?? 3));
                    foreach ($lines as $line) {
                        $this->ensure(abs($line->transaction_date->diffInDays($target->transaction_date)) <= $days || filled($exceptionReason), 'Informe justificativa para conciliar movimentos fora da margem de data.');
                    }
                } else {
                    $this->ensure($amount <= $this->cents($target->balance_amount), 'O valor distribuído excede o saldo da parcela.');
                }
                $prepared[] = ['target' => $target, 'remaining' => $amount];
            }
            $this->ensure(array_sum(array_column($prepared, 'remaining')) === $total, 'A soma dos valores distribuídos deve ser igual ao total do extrato.');
            $groupId = DB::table('bank_reconciliation_groups')->insertGetId([
                'company_id' => $companyId, 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $payments = [];
            foreach ($lines as $line) {
                $remaining = $this->cents($line->amount);
                foreach ($prepared as &$item) {
                    $allocated = min($remaining, $item['remaining']);
                    if ($allocated <= 0) {
                        continue;
                    }
                    $movement = $item['target'];
                    if ($type !== 'cash_movement') {
                        $service = app($type === 'receivable' ? AccountReceivableService::class : AccountPayableService::class);
                        $payment = $service->registerInstallmentPayment($item['target'], $allocated / 100, $line->transaction_date->toDateString(), [
                            'financial_account_id' => $line->financial_account_id, 'origin' => 'bank_statement',
                            'notes' => $line->description, 'user_id' => $userId,
                        ]);
                        $this->ensure($payment !== null && ! $service->hasError(), $service->getMessageUser() ?: 'Não foi possível registrar a baixa.');
                        $movement = CashMovement::query()->where('origin_type', $payment::class)->where('origin_id', $payment->id)->lockForUpdate()->first();
                        $this->ensure($movement !== null, 'Movimento da baixa não encontrado.');
                        $payments[] = ['type' => $type, 'id' => $payment->id];
                    }
                    $line->cashMovements()->attach($movement->id, ['amount' => $allocated]);
                    $remaining -= $allocated;
                    $item['remaining'] -= $allocated;
                }
                unset($item);
                $metadata = $line->metadata ?? [];
                if (isset($metadata['decision'])) {
                    $metadata['decision_history'][] = $metadata['decision'];
                }
                $metadata['decision'] = ['type' => 'group', 'group_id' => $groupId, 'resolved_by' => $userId, 'resolved_at' => now()->toDateTimeString()];
                $line->update(['bank_reconciliation_group_id' => $groupId, 'reconciliation_status' => 'reconciled', 'reconciled_at' => now(), 'metadata' => $metadata]);
                $this->audit($line, 'group_reconciled', $userId, ['group_id' => $groupId, 'type' => $type, 'exception_reason' => $exceptionReason]);
            }
            DB::table('bank_reconciliation_groups')->where('id', $groupId)->update(['metadata' => json_encode(['payments' => $payments, 'type' => $type, 'items' => $items, 'exception_reason' => $exceptionReason, 'resolved_by' => $userId])]);
        });
    }

    public function reverse(BankStatementLine $line, ?int $userId, string $reason): void
    {
        DB::transaction(function () use ($line, $userId, $reason) {
            $this->ensure(filled($reason), 'Informe o motivo do desfazimento.');
            $group = DB::table('bank_reconciliation_groups')->where('id', $line->bank_reconciliation_group_id)->where('company_id', $line->company_id)->lockForUpdate()->first();
            $this->ensure($group !== null, 'Grupo de conciliação não encontrado.');
            $lines = BankStatementLine::query()->where('bank_reconciliation_group_id', $group->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $member) {
                $this->ensure(in_array($member->reconciliation_status, [BankStatementLineStatus::RECONCILED, BankStatementLineStatus::NEEDS_REVIEW], true), 'O grupo contém linhas em situação incompatível.');
                $member->cashMovements()->detach();
            }
            $metadata = json_decode($group->metadata, true, flags: JSON_THROW_ON_ERROR);
            foreach ($metadata['payments'] as $item) {
                $receivable = $item['type'] === 'receivable';
                $model = $receivable ? AccountReceivableInstallmentPayment::class : AccountPayableInstallmentPayment::class;
                $service = app($receivable ? AccountReceivableService::class : AccountPayableService::class);
                $payment = $model::query()->where('company_id', $line->company_id)->lockForUpdate()->find($item['id']);
                $this->ensure($payment !== null, 'Baixa do grupo não encontrada. O desfazimento foi cancelado.');
                $this->ensure($service->deleteInstallmentPayment($payment), $service->getMessageUser() ?: 'Não foi possível desfazer a baixa.');
            }
            foreach ($lines as $member) {
                $history = $member->metadata ?? [];
                $history['decision_history'][] = $history['decision'] ?? [];
                $history['decision'] = ['type' => 'reconciliation_reversed', 'group_id' => $group->id, 'reason' => $reason, 'resolved_by' => $userId, 'resolved_at' => now()->toDateTimeString()];
                $member->update(['bank_reconciliation_group_id' => null, 'reconciliation_status' => 'pending', 'reconciled_at' => null, 'needs_review_at' => null, 'review_reason' => null, 'metadata' => $history]);
                $this->audit($member, 'group_reversed', $userId, ['group_id' => $group->id, 'reason' => $reason]);
            }
        });
    }

    private function audit(BankStatementLine $line, string $event, ?int $userId, array $metadata): void
    {
        $audit = app(AuditRecorder::class);
        $import = $line->import;
        $audit->recordModelEvent($import, 'bank_statement_import.'.$event, "Conciliação composta da linha #{$line->id}", null, $audit->snapshot($import), $userId, null, ['bank_statement_line_id' => $line->id, ...$metadata]);
    }

    private function cents(mixed $amount): int
    {
        $this->ensure(is_numeric($amount) && is_finite((float) $amount), 'Valor monetário inválido.');

        return (int) round((float) $amount * 100);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['reconciliation' => [$message]]);
        }
    }
}
