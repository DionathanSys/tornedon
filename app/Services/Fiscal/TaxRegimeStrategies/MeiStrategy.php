<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfile;

class MeiStrategy implements TaxRegimeStrategyInterface
{
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfile $profile,
    ): FiscalDecisionDTO {
        return new FiscalDecisionDTO(
            cfop: $this->resolveCfop($context, $profile),
            cstIcms: null,
            csosn: $profile->icms_csosn_default ?? '102',
            modBcIcms: $profile->icms_modalidade_base_calculo,
            aliquotaIcms: $profile->icms_aliquota_interna ?? 0,
            reducaoBaseIcms: $profile->icms_reducao_base,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: $profile->pis_cst_default ?? '99',
            aliquotaPis: $profile->pis_aliquota_default ?? 0,
            cstCofins: $profile->cofins_cst_default ?? '99',
            aliquotaCofins: $profile->cofins_aliquota_default ?? 0,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
            source: 'regime_default',
        );
    }

    private function resolveCfop(FiscalContextDTO $context, FiscalProfile $profile): ?string
    {
        if ($context->operationNature !== null) {
            $cfop = $profile->getCfopForNature($context->operationNature);
            if ($cfop !== null) {
                return $cfop;
            }
        }

        // Fallback: venda dentro/fora do estado
        if ($context->operationType === FiscalOperationType::SALE) {
            return $context->isInterestadual() ? '6102' : '5102';
        }

        return null;
    }
}
