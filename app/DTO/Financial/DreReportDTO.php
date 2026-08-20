<?php

namespace App\DTO\Financial;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DreReportDTO
{
    /**
     * @param  array<int, int>  $companyIds
     * @param  Collection<int, DreLineResultDTO>  $lines
     */
    public function __construct(
        public int $dreModelId,
        public array $companyIds,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public string $mode,
        public string $view,
        public float $revenueBase,
        public Collection $lines,
    ) {}
}
