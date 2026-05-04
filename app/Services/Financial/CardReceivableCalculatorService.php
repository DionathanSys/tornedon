<?php

namespace App\Services\Financial;

use App\Domain\DTO\Financial\CardReceivableCalculationDTO;
use App\Models\CardPaymentProfile;
use Carbon\Carbon;

class CardReceivableCalculatorService
{
    public function calculateFromProfile(
        CardPaymentProfile $profile,
        float $grossAmount,
        Carbon|string $paymentDate
    ): CardReceivableCalculationDTO {
        $feePercent = (float) $profile->fee_percent;
        $feeFixed = round((float) $profile->fee_fixed, 2);
        $settlementDays = (int) $profile->settlement_days;
        $normalizedGross = round($grossAmount, 2);

        $feeAmount = round((($normalizedGross * $feePercent) / 100) + $feeFixed, 2);
        $netAmount = round($normalizedGross - $feeAmount, 2);
        $expectedSettlementDate = Carbon::parse($paymentDate)
            ->addDays($settlementDays)
            ->toDateString();

        return new CardReceivableCalculationDTO(
            grossAmount: $normalizedGross,
            feePercent: $feePercent,
            feeFixed: $feeFixed,
            feeAmount: $feeAmount,
            netAmount: $netAmount,
            settlementDays: $settlementDays,
            expectedSettlementDate: $expectedSettlementDate,
            snapshot: $this->buildSnapshot($profile),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(CardPaymentProfile $profile): array
    {
        return [
            'profile_id' => $profile->id,
            'name' => $profile->name,
            'brand' => $profile->brand,
            'acquirer' => $profile->acquirer,
            'fee_percent' => (float) $profile->fee_percent,
            'fee_fixed' => round((float) $profile->fee_fixed, 2),
            'settlement_days' => (int) $profile->settlement_days,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
