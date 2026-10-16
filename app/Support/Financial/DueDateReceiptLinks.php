<?php

namespace App\Support\Financial;

use App\Models\AccountReceivableInstallment;

final class DueDateReceiptLinks
{
    /** Only associate an unambiguous outstanding charge on the same account. */
    public static function forInstallment(AccountReceivableInstallment $installment, int $financialAccountId): array
    {
        $candidates = collect();
        foreach (['pixCharges' => 'pix_charge_id', 'bankSlips' => 'bank_slip_id'] as $relation => $field) {
            foreach ($installment->{$relation}()->where('status', 'registered')->with('connection')->get() as $charge) {
                if ((int) ($charge->financial_account_id ?? $charge->connection?->financial_account_id) === $financialAccountId
                    && (int) $charge->company_id === (int) $installment->company_id
                    && (int) round((float) $charge->amount * 100) === (int) round((float) $installment->balance_amount * 100)) {
                    $candidates->push([$field => $charge->id]);
                }
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : [];
    }
}
