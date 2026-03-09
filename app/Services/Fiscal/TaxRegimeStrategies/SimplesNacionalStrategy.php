<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfileVersion;

class SimplesNacionalStrategy implements TaxRegimeStrategyInterface
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
            modBcSt: $version->icms_st_aliquota !== null ? '4' : null,
            aliquotaMvaSt: $version->icms_st_mva,
            aliquotaSt: $version->icms_st_aliquota,
            reducaoBaseSt: $version->icms_st_reducao_base,
            cstPis: $version->pis_cst_default ?? '49',
            aliquotaPis: $version->pis_aliquota_default ?? 0.65,
            cstCofins: $version->cofins_cst_default ?? '49',
            aliquotaCofins: $version->cofins_aliquota_default ?? 3.00,
            cstIpi: $version->ipi_cst_default,
            aliquotaIpi: $version->ipi_aliquota_default,
            enquadramentoIpi: $version->ipi_enquadramento,
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

        if ($context->operationType === FiscalOperationType::SALE) {
            return $context->isInterestadual() ? '6102' : '5102';
        }

        return null;
    }
}
