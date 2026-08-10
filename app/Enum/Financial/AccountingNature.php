<?php

namespace App\Enum\Financial;

enum AccountingNature: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function description(): string
    {
        return match ($this) {
            self::DEBIT => 'Débito',
            self::CREDIT => 'Crédito',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
