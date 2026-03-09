<?php

namespace App\Enum\Tax;

enum IcmsCsosn: string
{
    case TRIBUTADA_COM_CREDITO = '101';
    case TRIBUTADA_SEM_CREDITO = '102';
    case ISENCAO_FAIXA_RECEITA = '103';
    case IMUNE = '200';
    case TRIBUTADA_COM_CREDITO_E_ST = '201';
    case TRIBUTADA_SEM_CREDITO_E_ST = '202';
    case ISENCAO_FAIXA_RECEITA_E_ST = '203';
    case IMUNE_COM_CREDITO = '300';
    case NAO_TRIBUTADA = '400';
    case COBRADO_ANTERIORMENTE_POR_ST = '500';
    case OUTROS = '900';

    public function description(): string
    {
        return match ($this) {
            self::TRIBUTADA_COM_CREDITO => '101 - Tributada com permissão de crédito',
            self::TRIBUTADA_SEM_CREDITO => '102 - Tributada sem permissão de crédito',
            self::ISENCAO_FAIXA_RECEITA => '103 - Isenção do ICMS para faixa de receita bruta',
            self::IMUNE => '200 - Imune',
            self::TRIBUTADA_COM_CREDITO_E_ST => '201 - Tributada com crédito e cobrança do ICMS por ST',
            self::TRIBUTADA_SEM_CREDITO_E_ST => '202 - Tributada sem crédito e cobrança do ICMS por ST',
            self::ISENCAO_FAIXA_RECEITA_E_ST => '203 - Isenção para faixa de receita bruta e cobrança do ICMS por ST',
            self::IMUNE_COM_CREDITO => '300 - Imune com permissão de crédito',
            self::NAO_TRIBUTADA => '400 - Não tributada pelo Simples Nacional',
            self::COBRADO_ANTERIORMENTE_POR_ST => '500 - ICMS cobrado anteriormente por ST ou por antecipação',
            self::OUTROS => '900 - Outros',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
