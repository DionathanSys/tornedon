<?php

namespace App\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalProfile;
use App\Models\FiscalProfileVersion;
use App\Models\FiscalRule;
use App\Models\ProductTax;
use App\Services\Fiscal\TaxRegimeStrategies\TaxRegimeStrategyFactory;

class FiscalDecisionService
{
    public function __construct(
        private readonly RuleMatcher $ruleMatcher,
    ) {
    }

    /**
     * Resolve a decisão fiscal para um contexto.
     *
     * Hierarquia de prioridade:
     * 1. FiscalRule customizada (match por condições)
     * 2. ProductTax do produto (se configurado)
     * 3. Defaults do regime (Strategy)
     */
    public function resolve(FiscalContextDTO $context): FiscalDecisionDTO
    {
        $profile = FiscalProfile::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->first();

        if (!$profile) {
            return $this->emptyDecision();
        }

        $version = $profile->getActiveVersion();

        if (!$version) {
            return $this->emptyDecision();
        }

        // 1. Tentar regra customizada
        $ruleDecision = $this->tryCustomRule($context, $version);
        if ($ruleDecision !== null) {
            return $ruleDecision;
        }

        // 2. Tentar configuração do produto
        $productDecision = $this->tryProductTax($context, $version);
        if ($productDecision !== null) {
            return $productDecision;
        }

        // 3. Fallback: defaults do regime
        $strategy = TaxRegimeStrategyFactory::make($profile->tax_regime);

        return $strategy->resolveDefaults($context, $version);
    }

    /**
     * Retorna a versão ativa do perfil fiscal da empresa.
     */
    public function getActiveVersion(int $companyId): ?FiscalProfileVersion
    {
        $profile = FiscalProfile::where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        return $profile?->getActiveVersion();
    }

    private function tryCustomRule(FiscalContextDTO $context, FiscalProfileVersion $version): ?FiscalDecisionDTO
    {
        $rules = FiscalRule::where('fiscal_profile_version_id', $version->id)
            ->enabled()
            ->validAt($context->issuedAt)
            ->byPriority()
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $matchedRule = $this->ruleMatcher->findFirstMatch($rules, $context);

        if ($matchedRule === null) {
            return null;
        }

        $result = $matchedRule->result ?? [];

        return new FiscalDecisionDTO(
            cfop: $result['cfop'] ?? $this->resolveCfopFromVersion($context, $version),
            cstIcms: $result['cst_icms'] ?? null,
            csosn: $result['csosn'] ?? null,
            modBcIcms: $result['mod_bc'] ?? null,
            aliquotaIcms: isset($result['aliquota_icms']) ? (float) $result['aliquota_icms'] : null,
            reducaoBaseIcms: isset($result['reducao_base_icms']) ? (float) $result['reducao_base_icms'] : null,
            modBcSt: $result['mod_bc_st'] ?? null,
            aliquotaMvaSt: isset($result['aliquota_mva_st']) ? (float) $result['aliquota_mva_st'] : null,
            aliquotaSt: isset($result['aliquota_st']) ? (float) $result['aliquota_st'] : null,
            reducaoBaseSt: isset($result['reducao_base_st']) ? (float) $result['reducao_base_st'] : null,
            cstPis: $result['cst_pis'] ?? null,
            aliquotaPis: isset($result['aliquota_pis']) ? (float) $result['aliquota_pis'] : null,
            cstCofins: $result['cst_cofins'] ?? null,
            aliquotaCofins: isset($result['aliquota_cofins']) ? (float) $result['aliquota_cofins'] : null,
            cstIpi: $result['cst_ipi'] ?? null,
            aliquotaIpi: isset($result['aliquota_ipi']) ? (float) $result['aliquota_ipi'] : null,
            enquadramentoIpi: $result['enquadramento_ipi'] ?? null,
            ruleId: $matchedRule->id,
            ruleVersion: $version->version,
            source: 'fiscal_rule',
        );
    }

    private function tryProductTax(FiscalContextDTO $context, FiscalProfileVersion $version): ?FiscalDecisionDTO
    {
        if ($context->productId === null) {
            return null;
        }

        $productTax = ProductTax::where('product_id', $context->productId)->first();

        if (!$productTax) {
            return null;
        }

        $icms = $productTax->icms ?? [];
        $pis = $productTax->pis ?? [];
        $cofins = $productTax->cofins ?? [];
        $ipi = $productTax->ipi ?? [];

        // Só usar ProductTax se tem dados de impostos relevantes
        $hasIcms = !empty($icms['situacao_tributaria'] ?? $icms['aliquota'] ?? null);
        $hasPis = !empty($pis['situacao_tributaria'] ?? $pis['aliquota'] ?? null);

        if (!$hasIcms && !$hasPis) {
            return null;
        }

        return new FiscalDecisionDTO(
            cfop: $this->resolveCfopFromVersion($context, $version),
            cstIcms: $icms['situacao_tributaria'] ?? null,
            csosn: $icms['csosn'] ?? null,
            modBcIcms: $icms['modalidade_base_calculo'] ?? $version->icms_modalidade_base_calculo,
            aliquotaIcms: isset($icms['aliquota']) ? (float) $icms['aliquota'] : ($version->icms_aliquota_interna ?? 0),
            reducaoBaseIcms: isset($icms['reducao_base']) ? (float) $icms['reducao_base'] : $version->icms_reducao_base,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: $pis['situacao_tributaria'] ?? $version->pis_cst_default,
            aliquotaPis: isset($pis['aliquota']) ? (float) $pis['aliquota'] : $version->pis_aliquota_default,
            cstCofins: $cofins['situacao_tributaria'] ?? $version->cofins_cst_default,
            aliquotaCofins: isset($cofins['aliquota']) ? (float) $cofins['aliquota'] : $version->cofins_aliquota_default,
            cstIpi: $ipi['situacao_tributaria'] ?? $version->ipi_cst_default,
            aliquotaIpi: isset($ipi['aliquota']) ? (float) $ipi['aliquota'] : $version->ipi_aliquota_default,
            enquadramentoIpi: $ipi['enquadramento'] ?? $version->ipi_enquadramento,
            source: 'product_tax',
        );
    }

    private function resolveCfopFromVersion(FiscalContextDTO $context, FiscalProfileVersion $version): ?string
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

    private function emptyDecision(): FiscalDecisionDTO
    {
        return new FiscalDecisionDTO(
            cfop: null,
            cstIcms: null,
            csosn: null,
            modBcIcms: null,
            aliquotaIcms: null,
            reducaoBaseIcms: null,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: null,
            aliquotaPis: null,
            cstCofins: null,
            aliquotaCofins: null,
            cstIpi: null,
            aliquotaIpi: null,
            enquadramentoIpi: null,
            source: 'regime_default',
        );
    }
}
