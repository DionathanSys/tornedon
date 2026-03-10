<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalProfile;

interface TaxRegimeStrategyInterface
{
    /**
     * Resolve os valores fiscais padrão para o regime tributário.
     *
     * O FiscalProfile contém as configurações customizadas da empresa.
     * A Strategy aplica os defaults do regime e sobrescreve com configurações do perfil.
     */
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfile $profile,
    ): FiscalDecisionDTO;
}
