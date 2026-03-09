<?php

namespace App\Enum\Tax;

enum IcmsModalidadeBaseCalculoSt: string
{
    case PRECO_TABELADO_OU_MAXIMO = '0';
    case LISTA_NEGATIVA = '1';
    case LISTA_POSITIVA = '2';
    case LISTA_NEUTRA = '3';
    case MARGEM_VALOR_AGREGADO = '4';
    case PAUTA = '5';
    case VALOR_OPERACAO = '6';

    public function description(): string
    {
        return match ($this) {
            self::PRECO_TABELADO_OU_MAXIMO => '0 - Preço tabelado ou máximo sugerido',
            self::LISTA_NEGATIVA => '1 - Lista Negativa (valor)',
            self::LISTA_POSITIVA => '2 - Lista Positiva (valor)',
            self::LISTA_NEUTRA => '3 - Lista Neutra (valor)',
            self::MARGEM_VALOR_AGREGADO => '4 - Margem Valor Agregado (%)',
            self::PAUTA => '5 - Pauta (valor)',
            self::VALOR_OPERACAO => '6 - Valor da Operação',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
