<?php

namespace App\Domain\DTO\Quote;

use App\Enum\Quote\Destination;

class QuoteItemSourceDTO
{
    public function __construct(
        public readonly ?int $productStockId = null,
        public readonly ?int $productId = null,
        public readonly ?int $serviceId = null,
        public readonly ?string $code = null,
        public readonly ?string $name = null,
        public readonly ?string $unit = null,
        public readonly ?float $price = null,
        public readonly float $minSalePrice = 0,
        public readonly Destination $destination,
    ) {
    }
}
