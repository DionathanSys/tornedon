<?php

namespace App\Support\Financial;

use App\Models\AccountReceivableInstallment;
use Illuminate\Validation\ValidationException;

final class InstallmentPaymentMethodGuard
{
    public static function validate(AccountReceivableInstallment $installment, array $data): void
    {
        if (! array_key_exists('payment_method', $data)) {
            return;
        }

        $candidate = clone $installment;
        $candidate->payment_method = $data['payment_method'];

        if ($candidate->payment_method === $installment->payment_method) {
            return;
        }

        if ($installment->bankSlips()->whereNotIn('status', ['canceled', 'registration_failed', 'payment_returned'])->exists()
            || $installment->pixCharges()->whereNotIn('status', ['canceled', 'registration_failed', 'expired'])->exists()) {
            throw ValidationException::withMessages([
                'payment_method' => ['Cancele ou resolva a cobrança bancária vinculada antes de alterar a forma de pagamento da parcela.'],
            ]);
        }

        if ($installment->accountReceivable?->invoice?->operations()->where('active_slot', 1)->exists()) {
            throw ValidationException::withMessages([
                'payment_method' => ['Aguarde a conclusão da operação da fatura antes de alterar a forma de pagamento da parcela.'],
            ]);
        }
    }
}
