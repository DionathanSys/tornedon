<?php

namespace App\Enum\Tax;

enum IcmsCst: string
{
    case TRIBUTADA_INTEGRALMENTE = '00';
    case TRIBUTADA_COM_ST = '10';
    case REDUCAO_BASE_CALCULO = '20';
    case ISENTA_COM_ST = '30';
    case ISENTA = '40';
    case NAO_TRIBUTADA = '41';
    case SUSPENSAO = '50';
    case DIFERIMENTO = '51';
    case COBRADO_ANTERIORMENTE_POR_ST = '60';
    case REDUCAO_BASE_COM_ST = '70';
    case OUTRAS = '90';

    public function description(): string
    {
        return match ($this) {
            self::TRIBUTADA_INTEGRALMENTE => '00 - Tributada integralmente',
            self::TRIBUTADA_COM_ST => '10 - Tributada e com cobrança do ICMS por ST',
            self::REDUCAO_BASE_CALCULO => '20 - Com redução de base de cálculo',
            self::ISENTA_COM_ST => '30 - Isenta ou não tributada e com cobrança do ICMS por ST',
            self::ISENTA => '40 - Isenta',
            self::NAO_TRIBUTADA => '41 - Não tributada',
            self::SUSPENSAO => '50 - Suspensão',
            self::DIFERIMENTO => '51 - Diferimento',
            self::COBRADO_ANTERIORMENTE_POR_ST => '60 - ICMS cobrado anteriormente por ST',
            self::REDUCAO_BASE_COM_ST => '70 - Com redução de BC e cobrança do ICMS por ST',
            self::OUTRAS => '90 - Outras',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
