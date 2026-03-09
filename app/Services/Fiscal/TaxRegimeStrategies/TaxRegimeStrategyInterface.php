<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalProfileVersion;

interface TaxRegimeStrategyInterface
{
    /**
     * Resolve os valores fiscais padrão para o regime tributário.
     *
     * O FiscalProfileVersion contém as configurações customizadas da empresa.
     * A Strategy aplica os defaults do regime e sobrescreve com configurações do perfil.
     */
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfileVersion $version,
    ): FiscalDecisionDTO;
}
