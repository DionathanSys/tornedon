<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankReconciliationGroup;
use App\Models\BankStatementLine;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountPayable\Actions\Installment\SyncAccountPayableStatusFromInstallmentsAction;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\CashMovementService;
use App\Support\Financial\GroupedFinancialEffect;
use App\Support\Financial\InstallmentPaymentGuard;
use App\Support\Financial\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiptWithRetainedExpensesReversalService
{
    /** Administrative correction preserves rows and never posts a bank return. */
    public function correct(int $companyId, int $groupId, int $userId, string $reason): void
    {
        ReconcileReceiptWithRetainedExpensesService::authorize($companyId, $userId);
        $this->reason($reason);
        DB::transaction(function () use ($companyId, $groupId, $userId, $reason): void {
            $lines = BankStatementLine::where('company_id', $companyId)->where('bank_reconciliation_group_id', $groupId)->orderBy('id')->lockForUpdate()->get();
            $group = $this->group($companyId, $groupId);
            if ($group->status === 'cancelled') {
                return;
            }
            $this->ensure($group->status === 'confirmed' && $lines->isNotEmpty(), 'A operação não pode ser corrigida nesta situação.');
            $items = $group->items()->with(['receivablePayment.installment.accountReceivable', 'payablePayment.installment.accountPayable', 'cashMovement'])->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $this->ensure(in_array($line->reconciliation_status->value, ['reconciled', 'needs_review'], true), 'Linha bancária em situação incompatível.');
            }
            foreach ($items as $item) {
                $payment = $item->receivablePayment ?? $item->payablePayment;
                $this->ensure($payment && $item->created_payment && $item->cashMovement, 'Componente da operação não encontrado.');
                InstallmentPaymentGuard::lock($payment->installment);
                $payment = $payment->newQuery()->where('company_id', $companyId)->lockForUpdate()->findOrFail($payment->id);
                $this->ensure(! $payment->bank_confirmed_at && ! $payment->getAttribute('bank_slip_id') && ! $payment->getAttribute('pix_charge_id')
                    && Money::stored($payment, 'returned_amount') === 0 && ! $item->cashMovement->reversed_at && ! $item->cashMovement->reversals()->exists(), 'Existe confirmação bancária ou devolução posterior. Utilize a devolução efetiva.');
                $this->ensure((int) $payment->company_id === $companyId && (int) $item->cashMovement->company_id === $companyId
                    && Money::stored($payment) === Money::stored($item->cashMovement), 'Componente financeiro inconsistente.');
                $links = $item->cashMovement->statementAllocations()->get();
                $this->ensure($links->count() === 1 && $lines->contains('id', $links->first()->bank_statement_line_id), 'Movimento possui vínculos incompatíveis.');
            }
            $before = app(AuditRecorder::class)->snapshot($group);
            GroupedFinancialEffect::within($groupId, function () use ($group, $items, $lines, $userId, $reason): void {
                $group->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancellation_reason' => $reason]);
                foreach ($lines as $line) {
                    $line->cashMovements()->detach();
                    $metadata = $line->metadata ?? [];
                    $metadata['decision_history'][] = $metadata['decision'] ?? [];
                    $metadata['decision'] = ['type' => 'reconciliation_reversed', 'group_id' => $group->id, 'reason' => $reason, 'resolved_by' => $userId, 'resolved_at' => now()->toDateTimeString()];
                    $line->update(['bank_reconciliation_group_id' => null, 'cash_movement_id' => null, 'reconciliation_status' => 'pending', 'reconciled_at' => null, 'needs_review_at' => null, 'review_reason' => null, 'metadata' => $metadata]);
                }
                foreach ($items->where('role', 'receipt')->unique('receivablePayment.account_receivable_installment_id') as $item) {
                    app(AccountReceivableService::class)->resynchronizeAfterBankReturn($item->receivablePayment->installment->fresh());
                }
                foreach ($items->where('role', 'retained_expense') as $item) {
                    $payment = $item->payablePayment;
                    $service = app(AccountPayableService::class);
                    $service->recalculatePayableInstallment($payment->installment->fresh());
                    $sync = new SyncAccountPayableStatusFromInstallmentsAction($payment->installment->accountPayable);
                    $this->ensure($sync->execute() !== null && ! $sync->hasError(), 'Falha ao recalcular a despesa.');
                    if ($item->created_document) {
                        $this->ensure($service->cancel($payment->installment->accountPayable->fresh(), $reason, $userId), $service->getMessageUser() ?: 'Falha ao cancelar despesa.');
                    }
                }
            });
            app(BankReconciliationQualityService::class)->reverse($lines->first(), $reason, $groupId);
            app(AuditRecorder::class)->recordModelEvent($group, 'retained_receipt.corrected', 'Conciliação corrigida sem devolução bancária', $before, app(AuditRecorder::class)->snapshot($group->fresh()), $userId, metadata: ['reason' => $reason, 'components' => $items->toArray()]);
        });
    }

    /** Full receipt return; fees are refunded only when explicitly selected. */
    public function returnFunds(int $companyId, int $groupId, int $userId, string $date, string $reason, array $refundedExpenseIds = []): void
    {
        ReconcileReceiptWithRetainedExpensesService::authorize($companyId, $userId);
        $this->reason($reason);
        Validator::make(compact('date', 'refundedExpenseIds'), ['date' => ['required', 'date_format:Y-m-d'], 'refundedExpenseIds' => ['array'], 'refundedExpenseIds.*' => ['integer', 'distinct']])->validate();
        DB::transaction(function () use ($companyId, $groupId, $userId, $date, $reason, $refundedExpenseIds): void {
            BankStatementLine::where('company_id', $companyId)->where('bank_reconciliation_group_id', $groupId)->orderBy('id')->lockForUpdate()->get();
            $group = $this->group($companyId, $groupId);
            $this->ensure($group->status === 'confirmed', 'A operação já foi corrigida ou devolvida.');
            $items = $group->items()->with(['receivablePayment.installment', 'payablePayment.installment', 'cashMovement'])->orderBy('id')->lockForUpdate()->get();
            $this->ensure(count(array_intersect($refundedExpenseIds, $items->where('role', 'retained_expense')->modelKeys())) === count($refundedExpenseIds), 'Selecione despesas desta operação.');
            GroupedFinancialEffect::within($groupId, function () use ($items, $date, $reason, $refundedExpenseIds, $userId): void {
                foreach ($items as $item) {
                    if ($item->role === 'retained_expense' && ! in_array($item->id, $refundedExpenseIds, true)) {
                        continue;
                    }
                    $payment = $item->receivablePayment ?? $item->payablePayment;
                    InstallmentPaymentGuard::lock($payment->installment);
                    $payment = $payment->newQuery()->where('company_id', $item->company_id)->lockForUpdate()->findOrFail($payment->id);
                    $this->ensure(! $payment->bank_confirmed_at && ! $payment->getAttribute('bank_slip_id') && ! $payment->getAttribute('pix_charge_id'), 'Recebimento confirmado por provedor exige devolução confirmada pelo provedor.');
                    $this->ensure($date >= $payment->payment_date->toDateString() && Money::stored($payment, 'returned_amount') === 0, 'Data inválida ou pagamento já devolvido.');
                    $cash = app(CashMovementService::class);
                    $reversal = $item->role === 'receipt' ? $cash->reverseForReceivablePayment($payment, $userId, $date) : $cash->reverseForPayablePayment($payment, $userId, $date);
                    $this->ensure($reversal !== null && ! $cash->hasError(), $cash->getMessageUser() ?: 'Falha ao registrar devolução.');
                    $payment->update(['returned_amount' => Money::decimal(Money::stored($payment))]);
                    if ($item->role === 'receipt') {
                        app(AccountReceivableService::class)->resynchronizeAfterBankReturn($payment->installment->fresh());
                    } else {
                        $payables = app(AccountPayableService::class);
                        $payables->recalculatePayableInstallment($payment->installment->fresh());
                        $sync = new SyncAccountPayableStatusFromInstallmentsAction($payment->installment->accountPayable);
                        $this->ensure($sync->execute() !== null && ! $sync->hasError(), 'Falha ao recalcular despesa devolvida.');
                        $this->ensure($payables->cancel($payment->installment->accountPayable->fresh(), $reason, $userId), $payables->getMessageUser());
                    }
                    $item->update(['returned_at' => now()]);
                }
            });
            $group->update(['status' => 'returned', 'metadata' => [...$group->metadata, 'return_date' => $date, 'return_reason' => $reason, 'refunded_expense_ids' => $refundedExpenseIds]]);
            app(AuditRecorder::class)->recordModelEvent($group, 'retained_receipt.returned', 'Devolução efetiva de recebimento com despesas retidas', actorUserId: $userId, metadata: ['date' => $date, 'reason' => $reason, 'refunded_expense_ids' => $refundedExpenseIds]);
        });
    }

    private function group(int $companyId, int $groupId): BankReconciliationGroup
    {
        return BankReconciliationGroup::where('company_id', $companyId)->where('operation_type', BankReconciliationGroup::RETAINED_RECEIPT)->lockForUpdate()->findOrFail($groupId);
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'min:5', 'max:2000']])->validate();
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['operation' => $message]);
        }
    }
}
