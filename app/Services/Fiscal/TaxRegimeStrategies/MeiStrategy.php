<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfileVersion;

class MeiStrategy implements TaxRegimeStrategyInterface
{
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfileVersion $version,
    ): FiscalDecisionDTO {
        return new FiscalDecisionDTO(
            cfop: $this->resolveCfop($context, $version),
            cstIcms: null,
            csosn: $version->icms_csosn_default ?? '102',
            modBcIcms: $version->icms_modalidade_base_calculo,
            aliquotaIcms: $version->icms_aliquota_interna ?? 0,
            reducaoBaseIcms: $version->icms_reducao_base,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: $version->pis_cst_default ?? '99',
            aliquotaPis: $version->pis_aliquota_default ?? 0,
            cstCofins: $version->cofins_cst_default ?? '99',
            aliquotaCofins: $version->cofins_aliquota_default ?? 0,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
            source: 'regime_default',
        );
    }

    private function resolveCfop(FiscalContextDTO $context, FiscalProfileVersion $version): ?string
    {
        if ($context->operationNature !== null) {
            $cfop = $version->getCfopForNature($context->operationNature);
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
