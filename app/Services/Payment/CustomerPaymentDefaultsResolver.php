<?php

namespace App\Services\Payment;

use App\Models\CompanyPartner;
use App\Models\CompanyPreference;
use BackedEnum;

class CustomerPaymentDefaultsResolver
{
    /**
     * @return array{payment_method:?string,payment_condition:?string,payment_condition_details:?array}
     */
    public function resolve(
        int $companyId,
        ?int $customerId,
        mixed $paymentMethod = null,
        mixed $paymentCondition = null,
        ?array $paymentConditionDetails = null,
    ): array {
        $resolvedPaymentMethod = $this->normalizeValue($paymentMethod);
        $resolvedPaymentCondition = $this->normalizeValue($paymentCondition);

        if ($resolvedPaymentMethod !== null && $resolvedPaymentCondition !== null) {
            return [
                'payment_method' => $resolvedPaymentMethod,
                'payment_condition' => $resolvedPaymentCondition,
                'payment_condition_details' => $paymentConditionDetails,
            ];
        }

        $companyPartner = $customerId !== null
            ? CompanyPartner::query()
                ->where('company_id', $companyId)
                ->where('partner_id', $customerId)
                ->first()
            : null;

        return [
            'payment_method' => $resolvedPaymentMethod
                ?? $this->normalizeValue($companyPartner?->payment_method)
                ?? CompanyPreference::getDefaultPaymentMethod($companyId),
            'payment_condition' => $resolvedPaymentCondition
                ?? $this->normalizeValue($companyPartner?->payment_condition)
                ?? CompanyPreference::getDefaultPaymentCondition($companyId),
            'payment_condition_details' => $resolvedPaymentCondition !== null
                ? $paymentConditionDetails
                : ($companyPartner?->payment_condition !== null
                    ? $companyPartner->payment_condition_details
                    : CompanyPreference::get('default_payment_condition_details', $companyId)),
        ];
    }

    /**
     * @return array{payment_method:?string,payment_condition:?string,payment_condition_details:?array}
     */
    public function defaultsForCustomer(int $companyId, ?int $customerId): array
    {
        return $this->resolve($companyId, $customerId);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
