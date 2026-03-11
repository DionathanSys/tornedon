<?php

namespace App\Enum\FiscalDocument;

enum RpsTipo: string
{
    case RECIBO    = '1';
    case CONJUGADA = '2';
    case CUPOM     = '3';

    public function description(): string
    {
        return match ($this) {
            self::RECIBO    => 'Recibo Provisório de Serviços',
            self::CONJUGADA => 'RPS - Nota Fiscal Conjugada (Mista)',
            self::CUPOM     => 'Cupom',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
