<?php

namespace App\Services\Fiscal\TaxRegimeStrategies;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfile;

class LucroPresumidoStrategy implements TaxRegimeStrategyInterface
{
    public function resolveDefaults(
        FiscalContextDTO $context,
        FiscalProfile $profile,
    ): FiscalDecisionDTO {
        $aliquotaIcms = $this->resolveAliquotaIcms($context, $profile);

        return new FiscalDecisionDTO(
            cfop: $this->resolveCfop($context, $profile),
            cstIcms: $profile->icms_cst_default ?? '00',
            csosn: null,
            modBcIcms: $profile->icms_modalidade_base_calculo ?? '3',
            aliquotaIcms: $aliquotaIcms,
            reducaoBaseIcms: $profile->icms_reducao_base,
            modBcSt: $profile->icms_st_aliquota !== null ? '4' : null,
            aliquotaMvaSt: $profile->icms_st_mva,
            aliquotaSt: $profile->icms_st_aliquota,
            reducaoBaseSt: $profile->icms_st_reducao_base,
            cstPis: $profile->pis_cst_default ?? '01',
            aliquotaPis: $profile->pis_aliquota_default ?? 1.65,
            cstCofins: $profile->cofins_cst_default ?? '01',
            aliquotaCofins: $profile->cofins_aliquota_default ?? 7.60,
            cstIpi: $profile->ipi_cst_default,
            aliquotaIpi: $profile->ipi_aliquota_default,
            enquadramentoIpi: $profile->ipi_enquadramento,
            source: 'regime_default',
        );
    }

    private function resolveAliquotaIcms(FiscalContextDTO $context, FiscalProfile $profile): float
    {
        if ($context->isInterestadual() && $context->recipientUf !== null) {
            $aliquotaInter = $profile->getAliquotaInterestadual($context->recipientUf);
            if ($aliquotaInter !== null) {
                return $aliquotaInter;
            }
        }

        return $profile->icms_aliquota_interna ?? 0;
    }

    private function resolveCfop(FiscalContextDTO $context, FiscalProfile $profile): ?string
    {
        if ($context->operationNature !== null) {
            $cfop = $profile->getCfopForNature($context->operationNature);
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
