<?php

namespace App\Domain\DTO\Financial;

class CardReceivableCalculationDTO
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public readonly float $grossAmount,
        public readonly float $feePercent,
        public readonly float $feeFixed,
        public readonly float $feeAmount,
        public readonly float $netAmount,
        public readonly int $settlementDays,
        public readonly string $expectedSettlementDate,
        public readonly array $snapshot,
    ) {
    }
}
