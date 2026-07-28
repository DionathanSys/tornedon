<?php

namespace App\Domain\DTO\Requisition;

use App\Enum\Product\Unit;

class RequisitionItemDTO
{
    public function __construct(
        public readonly int $productStockId,
        public readonly int $productId,
        public readonly string $code,
        public readonly string $name,
        public readonly Unit|string $unit,
        public readonly float $price,
        public readonly float $minSalePrice = 0,
    ) {
    }
}
