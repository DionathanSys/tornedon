<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfileVersion;

class LucroRealStrategy implements TaxRegimeStrategyInterface
{
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfileVersion $version,
    ): FiscalDecisionDTO {
        $aliquotaIcms = $this->resolveAliquotaIcms($context, $version);

        return new FiscalDecisionDTO(
            cfop: $this->resolveCfop($context, $version),
            cstIcms: $version->icms_cst_default ?? '00',
            csosn: null,
            modBcIcms: $version->icms_modalidade_base_calculo ?? '3',
            aliquotaIcms: $aliquotaIcms,
            reducaoBaseIcms: $version->icms_reducao_base,
            modBcSt: $version->icms_st_aliquota !== null ? '4' : null,
            aliquotaMvaSt: $version->icms_st_mva,
            aliquotaSt: $version->icms_st_aliquota,
            reducaoBaseSt: $version->icms_st_reducao_base,
            cstPis: $version->pis_cst_default ?? '01',
            aliquotaPis: $version->pis_aliquota_default ?? 1.65,
            cstCofins: $version->cofins_cst_default ?? '01',
            aliquotaCofins: $version->cofins_aliquota_default ?? 7.60,
            cstIpi: $version->ipi_cst_default,
            aliquotaIpi: $version->ipi_aliquota_default,
            enquadramentoIpi: $version->ipi_enquadramento,
            source: 'regime_default',
        );
    }

    private function resolveAliquotaIcms(FiscalContextDTO $context, FiscalProfileVersion $version): float
    {
        if ($context->isInterestadual() && $context->recipientUf !== null) {
            $aliquotaInter = $version->getAliquotaInterestadual($context->recipientUf);
            if ($aliquotaInter !== null) {
                return $aliquotaInter;
            }
        }

        return $version->icms_aliquota_interna ?? 0;
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
