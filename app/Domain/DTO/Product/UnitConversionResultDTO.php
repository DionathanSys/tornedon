<?php

namespace App\Domain\DTO\Product;

class UnitConversionResultDTO
{
    public function __construct(
        public readonly int $productId,
        public readonly string $operationalUnit,
        public readonly float $operationalQuantity,
        public readonly string $baseUnit,
        public readonly float $baseQuantity,
        public readonly float $factor,
        public readonly string $displayRule,
    ) {
    }
}
