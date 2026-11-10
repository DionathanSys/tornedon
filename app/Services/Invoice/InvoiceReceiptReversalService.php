<?php

namespace App\Services\Invoice;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\BankingEvent;
use App\Models\BankSlip;
use App\Models\CashMovement;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\CashMovementService;
use App\Support\Financial\InstallmentPaymentGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceReceiptReversalService
{
    public function reverseManual(AccountReceivableInstallmentPayment $payment, ?int $userId): void
    {
        DB::transaction(function () use ($payment, $userId): void {
            $installment = InstallmentPaymentGuard::lock($payment->installment);
            $payment = AccountReceivableInstallmentPayment::where('company_id', $installment->company_id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->bank_confirmed_at || $payment->bank_slip_id || $payment->pix_charge_id) {
                throw ValidationException::withMessages(['payment' => 'Recebimento bancário exige o fluxo de devolução confirmado pelo provedor.']);
            }
            if (round($payment->returned_amount * 100) === round($payment->amount * 100)) {
                return;
            }
            if ($payment->financial_account_id && ! CashMovement::where('company_id', $payment->company_id)->where('origin_type', $payment::class)->where('origin_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'O recebimento não possui o movimento financeiro original. Regularize o vínculo antes do estorno.']);
            }
            $cash = app(CashMovementService::class);
            $cash->reverseForReceivablePayment($payment, $userId);
            if ($cash->hasError()) {
                throw ValidationException::withMessages(['payment' => $cash->getMessage()]);
            }
            $payment->update(['returned_amount' => $payment->amount]);
            app(AccountReceivableService::class)->resynchronizeAfterBankReturn($installment->fresh());
            app(AuditRecorder::class)->recordModelEvent($payment, 'invoice.payment_reversed', 'Recebimento estornado com preservação do vínculo à fatura', actorUserId: $userId);
        });
    }

    /** Existing receipts are acceptable only when the payment AND its own cash movement agree. */
    public function blockers(AccountReceivable $receivable): array
    {
        $errors = [];
        $receivable->load('installments.payments');
        foreach ($receivable->installments as $installment) {
            foreach ([$installment->bankSlips()->withTrashed()->get(), $installment->pixCharges()->withTrashed()->get()] as $charges) {
                foreach ($charges as $charge) {
                    $field = $charge instanceof BankSlip ? 'bank_slip_id' : 'pix_charge_id';
                    $pending = BankingEvent::where('company_id', $receivable->company_id)->where($field, $charge->id)
                        ->where(fn ($query) => $query->where('status', 'needs_review')->orWhere(fn ($query) => $query->whereIn('status', ['pending', 'failed'])->whereIn('payload->charge_status', ['3', '7'])))->exists();
                    if ($pending || in_array($charge->status->value, ['paid', 'partially_paid'], true)) {
                        $errors[] = "Cobrança #{$charge->id} possui recebimento ou evento bancário a regularizar.";
                    }
                }
            }
            if ((int) $installment->company_id !== (int) $receivable->company_id || (float) $installment->received_amount > 0) {
                $errors[] = "Parcela #{$installment->id} possui recebimento vigente ou divergência de empresa.";
            }
            foreach ($installment->payments as $payment) {
                if ((int) $payment->company_id !== (int) $receivable->company_id || (int) round($payment->amount * 100) !== (int) round($payment->returned_amount * 100)) {
                    $errors[] = "Recebimento #{$payment->id} exige estorno prévio integral.";

                    continue;
                }
                $movement = CashMovement::where('origin_type', $payment::class)->where('origin_id', $payment->id)->whereNull('reversal_of_id')->with('reversals')->first();
                if (! $movement && ! $payment->financial_account_id && ! $payment->bank_confirmed_at) {
                    continue; // A receipt without a financial account never generated cash.
                }
                $valid = $movement && (int) $movement->company_id === (int) $payment->company_id
                    && (int) $movement->financial_account_id === (int) $payment->financial_account_id
                    && (int) round($movement->amount * 100) === (int) round($payment->amount * 100)
                    && $movement->direction->value === 'inflow' && $movement->reversed_at;
                $returned = $valid ? $movement->reversals->filter(fn ($entry) => (int) $entry->company_id === (int) $payment->company_id && (int) $entry->financial_account_id === (int) $movement->financial_account_id && $entry->direction->value === 'outflow')->sum('amount') : 0;
                if (! $valid || (int) round($returned * 100) !== (int) round($payment->amount * 100)) {
                    $errors[] = "Recebimento #{$payment->id} não possui estorno financeiro correspondente.";
                }
            }
        }
        if ($receivable->paid || (float) $receivable->paid_amount > 0) {
            $errors[] = "Título #{$receivable->id} ainda possui saldo recebido.";
        }

        return array_values(array_unique($errors));
    }
}
