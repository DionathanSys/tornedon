<?php

namespace App\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalProfile;
use App\Models\FiscalProfileVersion;
use App\Models\OperationRule;
use App\Models\ProductTax;
use App\Services\Fiscal\TaxRegimeStrategies\TaxRegimeStrategyFactory;

class FiscalDecisionService
{
    /**
     * Resolve a decisão fiscal para um contexto.
     *
     * Separação de responsabilidades:
     * - CFOP     → OperationRule (obrigatório; bloqueia se não configurado)
     * - Alíquotas → ProductTax (por produto) ou estratégia do regime (fallback)
     */
    public function resolve(FiscalContextDTO $context): FiscalDecisionDTO
    {
        $profile = FiscalProfile::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->first();

        if (! $profile) {
            return $this->emptyDecision();
        }

        $version = $profile->getActiveVersion();

        if (! $version) {
            return $this->emptyDecision();
        }

        // 1. CFOP — obrigatório via OperationRule (lança exceção se não configurado)
        $cfop = $this->resolveCfopFromOperationRule($context);

        // 2. Alíquotas — ProductTax ou fallback de regime
        $productDecision = $this->tryProductTax($context, $version);

        if ($productDecision !== null) {
            return $productDecision->withCfop($cfop);
        }

        $strategy = TaxRegimeStrategyFactory::make($profile->tax_regime);

        return $strategy->resolveDefaults($context, $version)->withCfop($cfop);
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

    /**
     * Resolve o CFOP a partir da OperationRule configurada para a operação.
     *
     * A regra é OBRIGATÓRIA. Se não existe uma OperationRule ativa para a operação,
     * lança RuntimeException impedindo a emissão.
     */
    private function resolveCfopFromOperationRule(FiscalContextDTO $context): string
    {
        $rule = OperationRule::where('company_id', $context->companyId)
            ->where('operation_nature', $context->operationNature)
            ->where('is_active', true)
            ->first();

        if (! $rule) {
            throw new \RuntimeException(
                "Nenhuma regra fiscal configurada para a operação \"{$context->operationNature}\". "
                . 'Configure em Configurações → Regras por Operação.'
            );
        }

        return $rule->resolveCfopForNcm($context->productNcm);
    }

    /**
     * Tenta resolver alíquotas a partir do ProductTax do produto.
     * Retorna null se o produto não tem configuração fiscal suficiente.
     */
    private function tryProductTax(FiscalContextDTO $context, FiscalProfileVersion $version): ?FiscalDecisionDTO
    {
        if ($context->productId === null) {
            return null;
        }

        $productTax = ProductTax::where('product_id', $context->productId)->first();

        if (! $productTax) {
            return null;
        }

        $icms   = $productTax->icms ?? [];
        $pis    = $productTax->pis ?? [];
        $cofins = $productTax->cofins ?? [];
        $ipi    = $productTax->ipi ?? [];

        $hasIcms = ! empty($icms['situacao_tributaria'] ?? $icms['aliquota'] ?? null);
        $hasPis  = ! empty($pis['situacao_tributaria'] ?? $pis['aliquota'] ?? null);

        if (! $hasIcms && ! $hasPis) {
            return null;
        }

        // CFOP será sobreposto por withCfop() no resolve()
        return new FiscalDecisionDTO(
            cfop:             null,
            cstIcms:          $icms['situacao_tributaria'] ?? null,
            csosn:            $icms['csosn'] ?? null,
            modBcIcms:        $icms['modalidade_base_calculo'] ?? $version->icms_modalidade_base_calculo,
            aliquotaIcms:     isset($icms['aliquota']) ? (float) $icms['aliquota'] : ($version->icms_aliquota_interna ?? 0),
            reducaoBaseIcms:  isset($icms['reducao_base']) ? (float) $icms['reducao_base'] : $version->icms_reducao_base,
            modBcSt:          null,
            aliquotaMvaSt:    null,
            aliquotaSt:       null,
            reducaoBaseSt:    null,
            cstPis:           $pis['situacao_tributaria'] ?? $version->pis_cst_default,
            aliquotaPis:      isset($pis['aliquota']) ? (float) $pis['aliquota'] : $version->pis_aliquota_default,
            cstCofins:        $cofins['situacao_tributaria'] ?? $version->cofins_cst_default,
            aliquotaCofins:   isset($cofins['aliquota']) ? (float) $cofins['aliquota'] : $version->cofins_aliquota_default,
            cstIpi:           $ipi['situacao_tributaria'] ?? $version->ipi_cst_default,
            aliquotaIpi:      isset($ipi['aliquota']) ? (float) $ipi['aliquota'] : $version->ipi_aliquota_default,
            enquadramentoIpi: $ipi['enquadramento'] ?? $version->ipi_enquadramento,
            source:           'product_tax',
        );
    }

    private function emptyDecision(): FiscalDecisionDTO
    {
        return new FiscalDecisionDTO(
            cfop:             null,
            cstIcms:          null,
            csosn:            null,
            modBcIcms:        null,
            aliquotaIcms:     null,
            reducaoBaseIcms:  null,
            modBcSt:          null,
            aliquotaMvaSt:    null,
            aliquotaSt:       null,
            reducaoBaseSt:    null,
            cstPis:           null,
            aliquotaPis:      null,
            cstCofins:        null,
            aliquotaCofins:   null,
            cstIpi:           null,
            aliquotaIpi:      null,
            enquadramentoIpi: null,
            source:           'regime_default',
        );
    }
}
