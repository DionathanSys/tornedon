<?php

namespace App\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalProfile;
use App\Models\ProductTax;
use App\Services\Fiscal\Actions\ResolveCfopAction;
use App\Services\Fiscal\TaxRegimeStrategies\TaxRegimeStrategyFactory;

class FiscalDecisionService
{
    /**
     * Resolve a decisão fiscal para um contexto.
     *
     * Separação de responsabilidades:
     * - CFOP      → FiscalProfile (obrigatório por natureza da operação)
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

        // Tenta resolver pela nova tabela fiscal_rules
        $rule = app(FiscalRuleResolver::class)->resolve($context, $profile);

        if ($rule) {
            // Usa a regra estruturada
            $cfop = app(ResolveCfopAction::class)->execute($rule->cfop, $context);

            // Verifica product tax para override de CST e alíquotas
            $productDecision = $this->tryProductTax($context, $profile);

            if ($productDecision !== null) {
                // Se tem product tax, usa as alíquotas/CST dele, mas mantém o CFOP da regra
                return $productDecision->withCfop(
                    cfop: $cfop,
                    cstIbsCbs: $rule->cst_ibs_cbs,
                    classificacaoTributariaIbsCbs: $rule->classificacao_tributaria_ibs_cbs,
                    indicadorDoacaoIbsCbs: $rule->indicador_doacao_ibs_cbs,
                    aliquotaIbsEstadual: $rule->aliquota_ibs_estadual !== null ? (float) $rule->aliquota_ibs_estadual : null,
                    aliquotaIbsMunicipal: $rule->aliquota_ibs_municipal !== null ? (float) $rule->aliquota_ibs_municipal : null,
                    aliquotaCbs: $rule->aliquota_cbs !== null ? (float) $rule->aliquota_cbs : null,
                );
            }

            // Resolve defaults do regime tributário como base
            $strategy = TaxRegimeStrategyFactory::make($profile->tax_regime);
            $regimeDefaults = $strategy->resolveDefaults($context, $profile);

            // Mescla: regra fiscal sobrepõe apenas campos preenchidos, o resto vem do regime
            return new FiscalDecisionDTO(
                cfop: $cfop,
                cstIcms: $rule->cst_icms ?? $regimeDefaults->cstIcms,
                csosn: $rule->csosn ?? $regimeDefaults->csosn,
                modBcIcms: $regimeDefaults->modBcIcms,
                aliquotaIcms: $rule->aliquota_icms !== null ? (float) $rule->aliquota_icms : $regimeDefaults->aliquotaIcms,
                reducaoBaseIcms: $regimeDefaults->reducaoBaseIcms,
                modBcSt: $regimeDefaults->modBcSt,
                aliquotaMvaSt: $regimeDefaults->aliquotaMvaSt,
                aliquotaSt: $regimeDefaults->aliquotaSt,
                reducaoBaseSt: $regimeDefaults->reducaoBaseSt,
                cstPis: $rule->cst_pis ?? $regimeDefaults->cstPis,
                aliquotaPis: $rule->aliquota_pis !== null ? (float) $rule->aliquota_pis : $regimeDefaults->aliquotaPis,
                cstCofins: $rule->cst_cofins ?? $regimeDefaults->cstCofins,
                aliquotaCofins: $rule->aliquota_cofins !== null ? (float) $rule->aliquota_cofins : $regimeDefaults->aliquotaCofins,
                cstIpi: $rule->cst_ipi ?? $regimeDefaults->cstIpi,
                aliquotaIpi: $rule->aliquota_ipi !== null ? (float) $rule->aliquota_ipi : $regimeDefaults->aliquotaIpi,
                enquadramentoIpi: $regimeDefaults->enquadramentoIpi,
                cstIbsCbs: $rule->cst_ibs_cbs,
                classificacaoTributariaIbsCbs: $rule->classificacao_tributaria_ibs_cbs,
                indicadorDoacaoIbsCbs: $rule->indicador_doacao_ibs_cbs,
                aliquotaIbsEstadual: $rule->aliquota_ibs_estadual !== null ? (float) $rule->aliquota_ibs_estadual : null,
                aliquotaIbsMunicipal: $rule->aliquota_ibs_municipal !== null ? (float) $rule->aliquota_ibs_municipal : null,
                aliquotaCbs: $rule->aliquota_cbs !== null ? (float) $rule->aliquota_cbs : null,
                source: 'fiscal_rule',
            );
        }

        // Fallback: Lógica legada (cfop_rules json)
        // 1. CFOP — obrigatório via FiscalProfile (lança exceção se não configurado)
        $cfop = $this->resolveCfopFromFiscalProfile($context, $profile);

        // 2. Auto-swap do primeiro dígito (5↔6, 1↔2) com base na UF
        $cfop = app(ResolveCfopAction::class)->execute($cfop, $context);

        // 3. Alíquotas — ProductTax ou fallback de regime
        $productDecision = $this->tryProductTax($context, $profile);

        if ($productDecision !== null) {
            return $productDecision->withCfop($cfop);
        }

        $strategy = TaxRegimeStrategyFactory::make($profile->tax_regime);

        return $strategy->resolveDefaults($context, $profile)->withCfop($cfop);
    }

    /**
     * Resolve a decisão fiscal para um item NFS-e (ISS).
     *
     * Prioridade da alíquota ISS:
     *   1. Alíquota do cadastro do Serviço (context.serviceTaxRate)
     *   2. Alíquota padrão do Perfil Fiscal (iss_rate_default)
     */
    public function resolveNfse(FiscalContextDTO $context): FiscalDecisionDTO
    {
        $profile = $this->getActiveProfile($context->companyId);

        $issAliquota = $context->serviceTaxRate
            ?? $profile?->iss_rate_default
            ?? 0;

        $issRetido = $profile?->iss_withheld_default ?? false;

        $issExigibilidade = $context->issExigibility ?? '1'; // 1 = Exigível

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
            issAliquota: $issAliquota,
            issRetido: $issRetido,
            issExigibilidade: $issExigibilidade,
            source: 'nfse_service',
        );
    }

    /**
     * Retorna o perfil fiscal ativo da empresa.
     */
    public function getActiveProfile(int $companyId): ?FiscalProfile
    {
        return FiscalProfile::where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
    }

    private function resolveCfopFromFiscalProfile(FiscalContextDTO $context, FiscalProfile $profile): string
    {
        if ($context->operationNature === null) {
            throw new \RuntimeException('Natureza da operação não informada para resolver CFOP.');
        }

        $cfop = $profile->resolveCfopForOperationNature(
            $context->operationNature,
            $context->productNcm,
            $context->isCustomManufacturing
        );

        if ($cfop === null) {
            throw new \RuntimeException(
                "Nenhum CFOP configurado para a operação \"{$context->operationNature}\" no Perfil Fiscal. "
                .'Configure em Configurações → Perfil Fiscal → Regras de CFOP por Operação.'
            );
        }

        return $cfop;
    }

    /**
     * Tenta resolver alíquotas a partir do ProductTax do produto.
     * Retorna null se o produto não tem configuração fiscal suficiente.
     */
    private function tryProductTax(FiscalContextDTO $context, FiscalProfile $profile): ?FiscalDecisionDTO
    {
        if ($context->productId === null) {
            return null;
        }

        $productTax = ProductTax::where('product_id', $context->productId)->first();

        if (! $productTax) {
            return null;
        }

        $icms = $productTax->icms ?? [];
        $pis = $productTax->pis ?? [];
        $cofins = $productTax->cofins ?? [];
        $ipi = $productTax->ipi ?? [];

        $hasIcms = ! empty($icms['situacao_tributaria'] ?? $icms['aliquota'] ?? null);
        $hasPis = ! empty($pis['situacao_tributaria'] ?? $pis['aliquota'] ?? null);

        if (! $hasIcms && ! $hasPis) {
            return null;
        }

        // CFOP será sobreposto por withCfop() no resolve()
        return new FiscalDecisionDTO(
            cfop: null,
            cstIcms: $icms['situacao_tributaria'] ?? null,
            csosn: $icms['csosn'] ?? null,
            modBcIcms: $icms['modalidade_base_calculo'] ?? $profile->icms_modalidade_base_calculo,
            aliquotaIcms: isset($icms['aliquota']) ? (float) $icms['aliquota'] : ($profile->icms_aliquota_interna ?? 0),
            reducaoBaseIcms: isset($icms['reducao_base']) ? (float) $icms['reducao_base'] : $profile->icms_reducao_base,
            modBcSt: null,
            aliquotaMvaSt: null,
            aliquotaSt: null,
            reducaoBaseSt: null,
            cstPis: $pis['situacao_tributaria'] ?? $profile->pis_cst_default,
            aliquotaPis: isset($pis['aliquota']) ? (float) $pis['aliquota'] : $profile->pis_aliquota_default,
            cstCofins: $cofins['situacao_tributaria'] ?? $profile->cofins_cst_default,
            aliquotaCofins: isset($cofins['aliquota']) ? (float) $cofins['aliquota'] : $profile->cofins_aliquota_default,
            cstIpi: $ipi['situacao_tributaria'] ?? $profile->ipi_cst_default,
            aliquotaIpi: isset($ipi['aliquota']) ? (float) $ipi['aliquota'] : $profile->ipi_aliquota_default,
            enquadramentoIpi: $ipi['enquadramento'] ?? $profile->ipi_enquadramento,
            source: 'product_tax',
        );
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
