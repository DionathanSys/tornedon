<?php

namespace App\Enum\Financial;

enum DreOperation: string
{
    case ADD = 'add';
    case SUBTRACT = 'subtract';
    case NONE = 'none';

    public function description(): string
    {
        return match ($this) {
            self::ADD => 'Somar',
            self::SUBTRACT => 'Subtrair',
            self::NONE => 'Não calcular',
        };
    }

    public function multiplier(): int
    {
        return match ($this) {
            self::ADD => 1,
            self::SUBTRACT => -1,
            self::NONE => 0,
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
