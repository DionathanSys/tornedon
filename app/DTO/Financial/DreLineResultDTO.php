<?php

namespace App\DTO\Financial;

use Illuminate\Support\Collection;

final readonly class DreLineResultDTO
{
    /**
     * @param  Collection<int, self>  $children
     */
    public function __construct(
        public int $lineId,
        public string $name,
        public ?string $code,
        public string $lineType,
        public float $amount,
        public ?float $percentage,
        public int $depth,
        public int $displayDepth,
        public bool $isBold,
        public bool $isVisible,
        public Collection $children,
    ) {}
}
