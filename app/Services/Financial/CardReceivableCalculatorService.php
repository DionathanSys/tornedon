<?php

namespace App\Services\Financial;

use App\Domain\DTO\Financial\CardReceivableCalculationDTO;
use App\Models\CardInstitution;
use Carbon\Carbon;

class CardReceivableCalculatorService
{
    public function calculateFromProfile(
        CardInstitution $profile,
        float $grossAmount,
        Carbon|string $paymentDate
    ): CardReceivableCalculationDTO {
        $settlementDays = (int) $profile->settlement_days;
        $normalizedGross = round($grossAmount, 2);

        $expectedSettlementDate = Carbon::parse($paymentDate)
            ->addDays($settlementDays)
            ->toDateString();

        return new CardReceivableCalculationDTO(
            grossAmount: $normalizedGross,
            feePercent: 0,
            feeFixed: 0,
            feeAmount: 0,
            netAmount: $normalizedGross,
            settlementDays: $settlementDays,
            expectedSettlementDate: $expectedSettlementDate,
            snapshot: $this->buildSnapshot($profile),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(CardInstitution $profile): array
    {
        return [
            'profile_id' => $profile->id,
            'name' => $profile->name,
            'brand' => $profile->brand,
            'acquirer' => $profile->acquirer,
            'settlement_days' => (int) $profile->settlement_days,
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
