<?php

namespace App\Enum\Tax;

enum IcmsModalidadeBaseCalculo: string
{
    case MARGEM_VALOR_AGREGADO = '0';
    case PAUTA = '1';
    case PRECO_TABELADO_MAXIMO = '2';
    case VALOR_OPERACAO = '3';

    public function description(): string
    {
        return match ($this) {
            self::MARGEM_VALOR_AGREGADO => '0 - Margem Valor Agregado (%)',
            self::PAUTA => '1 - Pauta (Valor)',
            self::PRECO_TABELADO_MAXIMO => '2 - Preço Tabelado Máximo (Valor)',
            self::VALOR_OPERACAO => '3 - Valor da Operação',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
