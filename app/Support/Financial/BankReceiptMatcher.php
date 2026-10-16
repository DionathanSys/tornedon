<?php

namespace App\Support\Financial;

use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;

final class BankReceiptMatcher
{
    public static function dueDatePayment(AccountReceivableInstallment $installment, string $chargeField, int $chargeId, float $amount, int $accountId): ?AccountReceivableInstallmentPayment
    {
        if (! in_array($chargeField, ['pix_charge_id', 'bank_slip_id'], true)) {
            throw new \InvalidArgumentException('Tipo de cobrança inválido.');
        }
        $payments = $installment->payments()->where($chargeField, $chargeId)
            ->where('origin', 'due_date')->whereNull('bank_confirmed_at')->get();
        if ($payments->count() !== 1) {
            return null;
        }
        $payment = $payments->first();
        if ((int) $payment->financial_account_id !== $accountId
            || (int) round((float) $payment->amount * 100) !== (int) round($amount * 100)) {
            return null;
        }
        $movement = CashMovement::query()->where('origin_type', $payment::class)->where('origin_id', $payment->id)->first();
        if (! $movement || $movement->reversed_at !== null || (int) $movement->financial_account_id !== $accountId
            || (int) round((float) $movement->amount * 100) !== (int) round($amount * 100)) {
            return null;
        }

        return $payment;
    }
}
