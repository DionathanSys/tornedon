<?php

namespace App\Enum\Tax;

enum FiscalOperationType: string
{
    case SALE       = 'sale';
    case RETURN     = 'return';
    case REPAIR     = 'repair';
    case REMITTANCE = 'remittance';
    case TRANSFER   = 'transfer';
    case BONUS      = 'bonus';

    public function description(): string
    {
        return match ($this) {
            self::SALE       => 'Venda',
            self::RETURN     => 'Devolução',
            self::REPAIR     => 'Conserto/Reparo',
            self::REMITTANCE => 'Remessa',
            self::TRANSFER   => 'Transferência',
            self::BONUS      => 'Bonificação',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
