<?php

namespace App\Enum\Financial;

enum CashMovementDirection: string
{
    case INFLOW = 'inflow';
    case OUTFLOW = 'outflow';

    public function description(): string
    {
        return match ($this) {
            self::INFLOW => 'Entrada',
            self::OUTFLOW => 'Saida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFLOW => 'success',
            self::OUTFLOW => 'danger',
        };
    }

    public function multiplier(): int
    {
        return match ($this) {
            self::INFLOW => 1,
            self::OUTFLOW => -1,
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
