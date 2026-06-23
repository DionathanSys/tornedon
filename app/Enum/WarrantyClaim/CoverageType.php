<?php

namespace App\Enum\WarrantyClaim;

enum CoverageType: string
{
    case LABOR = 'labor';
    case PARTS = 'parts';
    case LABOR_AND_PARTS = 'labor_and_parts';

    public function description(): string
    {
        return match ($this) {
            self::LABOR => 'Mão de obra',
            self::PARTS => 'Peças',
            self::LABOR_AND_PARTS => 'Mão de obra e peças',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
