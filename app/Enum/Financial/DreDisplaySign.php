<?php

namespace App\Enum\Financial;

enum DreDisplaySign: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';
    case NATURAL = 'natural';

    public function description(): string
    {
        return match ($this) {
            self::POSITIVE => 'Positivo',
            self::NEGATIVE => 'Negativo',
            self::NATURAL => 'Natural',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
