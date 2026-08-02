<?php

namespace App\Support\Financial;

use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;

class InstallmentDescription
{
    public static function forPayableInstallment(AccountPayableInstallment $installment, ?array $overrides = null): string
    {
        $installment->loadMissing('accountPayable.supplier');

        return self::resolve(
            primary: $overrides['payment_description'] ?? null,
            secondary: $overrides['installment_description'] ?? $installment->description,
            tertiary: $installment->accountPayable?->description,
            fallback: self::fallbackForPayable(
                $installment->accountPayable,
                $installment->sequence_number,
            ),
        );
    }

    public static function forReceivableInstallment(AccountReceivableInstallment $installment, ?array $overrides = null): string
    {
        $installment->loadMissing('accountReceivable.customer');

        return self::resolve(
            primary: $overrides['payment_description'] ?? null,
            secondary: $overrides['installment_description'] ?? $installment->description,
            tertiary: $installment->accountReceivable?->description,
            fallback: self::fallbackForReceivable(
                $installment->accountReceivable,
                $installment->sequence_number,
            ),
        );
    }

    public static function forPayablePayment(AccountPayableInstallmentPayment $payment): string
    {
        $payment->loadMissing('installment.accountPayable.supplier');

        return self::forPayableInstallment($payment->installment, [
            'payment_description' => $payment->description,
        ]);
    }

    public static function forReceivablePayment(AccountReceivableInstallmentPayment $payment): string
    {
        $payment->loadMissing('installment.accountReceivable.customer');

        return self::forReceivableInstallment($payment->installment, [
            'payment_description' => $payment->description,
        ]);
    }

    public static function fallbackForPayable(?AccountPayable $accountPayable, ?string $sequenceNumber): string
    {
        $supplier = trim((string) ($accountPayable?->supplier?->name ?? 'Conta a pagar'));
        $document = trim((string) ($accountPayable?->document_number ?? $accountPayable?->note_number ?? 'Sem documento'));
        $sequence = trim((string) ($sequenceNumber ?: '01'));

        return sprintf('%s | Doc. %s | Parcela %s', $supplier, $document, $sequence);
    }

    public static function fallbackForReceivable(?AccountReceivable $accountReceivable, ?string $sequenceNumber): string
    {
        $customer = trim((string) ($accountReceivable?->counterparty_label ?? 'Conta a receber'));
        $document = trim((string) ($accountReceivable?->document_number ?? 'Sem documento'));
        $sequence = trim((string) ($sequenceNumber ?: '01'));

        return sprintf('%s | Doc. %s | Parcela %s', $customer, $document, $sequence);
    }

    private static function resolve(?string $primary, ?string $secondary, ?string $tertiary, string $fallback): string
    {
        foreach ([$primary, $secondary, $tertiary] as $candidate) {
            $normalized = trim((string) $candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $fallback;
    }
}
