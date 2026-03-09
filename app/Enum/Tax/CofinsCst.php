<?php

namespace App\Enum\Tax;

enum CofinsCst: string
{
    case ALIQUOTA_NORMAL = '01';
    case ALIQUOTA_DIFERENCIADA = '02';
    case QUANTIDADE_VENDIDA = '03';
    case MONOFASICA_ALIQUOTA_ZERO = '04';
    case SUBSTITUICAO_TRIBUTARIA = '05';
    case ALIQUOTA_ZERO = '06';
    case ISENTA = '07';
    case SEM_INCIDENCIA = '08';
    case SUSPENSAO = '09';
    case OUTRAS_SAIDAS = '49';
    case CREDITO_VINCULADO_RECEITA_TRIBUTADA = '50';
    case CREDITO_VINCULADO_RECEITA_NAO_TRIBUTADA = '51';
    case CREDITO_VINCULADO_EXPORTACAO = '52';
    case CREDITO_VINCULADO_TRIBUTADA_NAO_TRIBUTADA = '53';
    case CREDITO_VINCULADO_TRIBUTADA_EXPORTACAO = '54';
    case CREDITO_VINCULADO_NAO_TRIBUTADA_EXPORTACAO = '55';
    case CREDITO_VINCULADO_TRIBUTADA_NAO_TRIBUTADA_EXPORTACAO = '56';
    case CREDITO_PRESUMIDO_RECEITA_TRIBUTADA = '60';
    case CREDITO_PRESUMIDO_RECEITA_NAO_TRIBUTADA = '61';
    case CREDITO_PRESUMIDO_EXPORTACAO = '62';
    case CREDITO_PRESUMIDO_TRIBUTADA_NAO_TRIBUTADA = '63';
    case CREDITO_PRESUMIDO_TRIBUTADA_EXPORTACAO = '64';
    case CREDITO_PRESUMIDO_NAO_TRIBUTADA_EXPORTACAO = '65';
    case CREDITO_PRESUMIDO_TRIBUTADA_NAO_TRIBUTADA_EXPORTACAO = '66';
    case CREDITO_PRESUMIDO_OUTRAS = '67';
    case SEM_DIREITO_A_CREDITO = '70';
    case AQUISICAO_ISENCAO = '71';
    case AQUISICAO_SUSPENSAO = '72';
    case AQUISICAO_ALIQUOTA_ZERO = '73';
    case AQUISICAO_SEM_INCIDENCIA = '74';
    case AQUISICAO_POR_ST = '75';
    case OUTRAS_ENTRADAS = '98';
    case OUTRAS_OPERACOES = '99';

    public function description(): string
    {
        return match ($this) {
            self::ALIQUOTA_NORMAL => '01 - Operação Tributável (alíquota normal)',
            self::ALIQUOTA_DIFERENCIADA => '02 - Operação Tributável (alíquota diferenciada)',
            self::QUANTIDADE_VENDIDA => '03 - Operação Tributável (quantidade vendida)',
            self::MONOFASICA_ALIQUOTA_ZERO => '04 - Operação Tributável (monofásica - alíquota zero)',
            self::SUBSTITUICAO_TRIBUTARIA => '05 - Operação Tributável (ST)',
            self::ALIQUOTA_ZERO => '06 - Operação Tributável (alíquota zero)',
            self::ISENTA => '07 - Operação Isenta da Contribuição',
            self::SEM_INCIDENCIA => '08 - Operação sem Incidência da Contribuição',
            self::SUSPENSAO => '09 - Operação com Suspensão da Contribuição',
            self::OUTRAS_SAIDAS => '49 - Outras Operações de Saída',
            self::CREDITO_VINCULADO_RECEITA_TRIBUTADA => '50 - Crédito vinculado exclusivamente a receita tributada',
            self::CREDITO_VINCULADO_RECEITA_NAO_TRIBUTADA => '51 - Crédito vinculado exclusivamente a receita não tributada',
            self::CREDITO_VINCULADO_EXPORTACAO => '52 - Crédito vinculado exclusivamente a receita de exportação',
            self::CREDITO_VINCULADO_TRIBUTADA_NAO_TRIBUTADA => '53 - Crédito vinculado a receitas tributadas e não-tributadas',
            self::CREDITO_VINCULADO_TRIBUTADA_EXPORTACAO => '54 - Crédito vinculado a receitas tributadas e de exportação',
            self::CREDITO_VINCULADO_NAO_TRIBUTADA_EXPORTACAO => '55 - Crédito vinculado a receitas não-tributadas e de exportação',
            self::CREDITO_VINCULADO_TRIBUTADA_NAO_TRIBUTADA_EXPORTACAO => '56 - Crédito vinculado a receitas tributadas, não-tributadas e de exportação',
            self::CREDITO_PRESUMIDO_RECEITA_TRIBUTADA => '60 - Crédito presumido - receita tributada',
            self::CREDITO_PRESUMIDO_RECEITA_NAO_TRIBUTADA => '61 - Crédito presumido - receita não tributada',
            self::CREDITO_PRESUMIDO_EXPORTACAO => '62 - Crédito presumido - receita de exportação',
            self::CREDITO_PRESUMIDO_TRIBUTADA_NAO_TRIBUTADA => '63 - Crédito presumido - receitas tributadas e não-tributadas',
            self::CREDITO_PRESUMIDO_TRIBUTADA_EXPORTACAO => '64 - Crédito presumido - receitas tributadas e de exportação',
            self::CREDITO_PRESUMIDO_NAO_TRIBUTADA_EXPORTACAO => '65 - Crédito presumido - receitas não-tributadas e de exportação',
            self::CREDITO_PRESUMIDO_TRIBUTADA_NAO_TRIBUTADA_EXPORTACAO => '66 - Crédito presumido - receitas tributadas, não-tributadas e de exportação',
            self::CREDITO_PRESUMIDO_OUTRAS => '67 - Crédito presumido - outras operações',
            self::SEM_DIREITO_A_CREDITO => '70 - Operação de aquisição sem direito a crédito',
            self::AQUISICAO_ISENCAO => '71 - Operação de aquisição com isenção',
            self::AQUISICAO_SUSPENSAO => '72 - Operação de aquisição com suspensão',
            self::AQUISICAO_ALIQUOTA_ZERO => '73 - Operação de aquisição a alíquota zero',
            self::AQUISICAO_SEM_INCIDENCIA => '74 - Operação de aquisição sem incidência',
            self::AQUISICAO_POR_ST => '75 - Operação de aquisição por ST',
            self::OUTRAS_ENTRADAS => '98 - Outras Operações de Entrada',
            self::OUTRAS_OPERACOES => '99 - Outras Operações',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
