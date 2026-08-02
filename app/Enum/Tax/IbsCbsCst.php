<?php

namespace App\Enum\Tax;

enum IbsCbsCst: string
{
    case TRIBUTACAO_INTEGRAL = '000';
    case ALIQUOTAS_UNIFORMES = '010';
    case ALIQUOTAS_UNIFORMES_REDUZIDAS = '011';
    case ALIQUOTA_REDUZIDA = '200';
    case ALIQUOTA_FIXA = '220';
    case ALIQUOTA_FIXA_RATEADA = '221';
    case REDUCAO_BASE_CALCULO = '222';
    case ISENCAO = '400';
    case IMUNIDADE_NAO_INCIDENCIA = '410';
    case DIFERIMENTO = '510';
    case DIFERIMENTO_REDUCAO_ALIQUOTA = '515';
    case SUSPENSAO = '550';
    case TRIBUTACAO_MONOFASICA = '620';
    case TRANSFERENCIA_CREDITO = '800';
    case AJUSTES_IBS_ZFM = '810';
    case AJUSTES = '811';
    case REGIME_ESPECIFICO = '820';
    case EXCLUSAO_BASE_CALCULO = '830';

    public function description(): string
    {
        return match ($this) {
            self::TRIBUTACAO_INTEGRAL => '000 - Tributação integral',
            self::ALIQUOTAS_UNIFORMES => '010 - Tributação com alíquotas uniformes',
            self::ALIQUOTAS_UNIFORMES_REDUZIDAS => '011 - Tributação com alíquotas uniformes reduzidas',
            self::ALIQUOTA_REDUZIDA => '200 - Alíquota reduzida',
            self::ALIQUOTA_FIXA => '220 - Alíquota fixa',
            self::ALIQUOTA_FIXA_RATEADA => '221 - Alíquota fixa rateada',
            self::REDUCAO_BASE_CALCULO => '222 - Redução de Base de Cálculo',
            self::ISENCAO => '400 - Isenção',
            self::IMUNIDADE_NAO_INCIDENCIA => '410 - Imunidade e não incidência',
            self::DIFERIMENTO => '510 - Diferimento',
            self::DIFERIMENTO_REDUCAO_ALIQUOTA => '515 - Diferimento com redução de alíquota',
            self::SUSPENSAO => '550 - Suspensão',
            self::TRIBUTACAO_MONOFASICA => '620 - Tributação Monofásica',
            self::TRANSFERENCIA_CREDITO => '800 - Transferência de crédito',
            self::AJUSTES_IBS_ZFM => '810 - Ajustes de IBS na ZFM',
            self::AJUSTES => '811 - Ajustes',
            self::REGIME_ESPECIFICO => '820 - Tributação em declaração de regime específico',
            self::EXCLUSAO_BASE_CALCULO => '830 - Exclusão da Base de Cálculo',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
