<?php

namespace App\Enum\Financial;

enum FinancialAccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case DIGITAL_WALLET = 'digital_wallet';

    public function description(): string
    {
        return match ($this) {
            self::BANK => 'Banco',
            self::CASH => 'Caixa',
            self::DIGITAL_WALLET => 'Carteira Digital',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
