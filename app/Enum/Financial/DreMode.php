<?php

namespace App\Enum\Financial;

enum DreMode: string
{
    case COMPETENCE = 'competence';
    case CASH = 'cash';

    public function description(): string
    {
        return match ($this) {
            self::COMPETENCE => 'Competência',
            self::CASH => 'Caixa',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
