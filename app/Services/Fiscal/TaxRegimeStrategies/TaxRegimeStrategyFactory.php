<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Enum\Tax\TaxRegime;

class TaxRegimeStrategyFactory
{
    /**
     * Retorna a estratégia de cálculo fiscal para o regime tributário informado.
     */
    public static function make(TaxRegime $regime): TaxRegimeStrategyInterface
    {
        return match ($regime) {
            TaxRegime::MEI              => new MeiStrategy(),
            TaxRegime::SIMPLES_NACIONAL => new SimplesNacionalStrategy(),
            TaxRegime::LUCRO_PRESUMIDO  => new LucroPresumidoStrategy(),
            TaxRegime::LUCRO_REAL       => new LucroRealStrategy(),
        };
    }
}
