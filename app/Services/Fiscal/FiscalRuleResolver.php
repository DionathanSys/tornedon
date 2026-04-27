<?php

namespace App\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Models\FiscalProfile;
use App\Models\FiscalRule;
use App\Services\Fiscal\Actions\ResolveCfopAction;
use Illuminate\Support\Facades\Log;

class FiscalRuleResolver
{
    public function __construct(
        private readonly ResolveCfopAction $resolveCfopAction
    ) {
    }

    /**
     * Resolve a regra fiscal mais adequada para o contexto atual.
     */
    public function resolve(FiscalContextDTO $context, FiscalProfile $profile): ?FiscalRule
    {
        if ($context->operationNature === null) {
            return null;
        }

        // 1. Carregar todas as regras ativas da empresa para a nature + regime
        $rules = FiscalRule::where('company_id', $context->companyId)
            ->where('is_active', true)
            ->where('operation_nature', $context->operationNature)
            ->where('tax_regime', $profile->tax_regime->value)
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $now = now()->toDateString();

        // 2. Filtrar regras candidatas
        $candidates = $rules->filter(function (FiscalRule $rule) use ($context, $now) {

            Log::info('FiscalRuleResolver: Candidata', [
                'rule_id' => $rule->id,
                'context'   => $context,
            ]);
            
            // Validade temporal
            if ($rule->valid_from !== null && $rule->valid_from->toDateString() > $now) {
                return false;
            }
            if ($rule->valid_until !== null && $rule->valid_until->toDateString() < $now) {
                return false;
            }

            // Filtro rígido: se a regra define um valor e ele não bate, descarta
            if ($rule->product_origin !== null && $rule->product_origin !== $context->productOrigin) {
                return false;
            }

            if ($rule->has_st !== null) {
                if ($rule->has_st !== $context->productHasSt) {
                    return false;
                }
            }

            if ($rule->ncm_prefix !== null) {
                if ($context->productNcm === null || !str_starts_with($context->productNcm, $rule->ncm_prefix)) {
                    return false;
                }
            }

            if ($rule->recipient_type !== null && $rule->recipient_type !== $context->recipientTaxpayerType) {
                return false;
            }

            if ($rule->is_final_consumer !== null && $rule->is_final_consumer !== $context->recipientFinalConsumer) {
                return false;
            }

            if ($rule->is_interestadual !== null && $rule->is_interestadual !== $context->isInterestadual()) {
                return false;
            }

            return true;
        });

        if ($candidates->isEmpty()) {
            return null;
        }

        Log::info('FiscalRuleResolver: Candidata', [
            'candidates'   => $candidates,
        ]);

        // 3. Pontuar cada regra
        $scored = $candidates->map(function (FiscalRule $rule) {
            $score = 0;
            if ($rule->ncm_prefix !== null) {
                $score += 8 + strlen($rule->ncm_prefix);
            }
            if ($rule->has_st !== null) {
                $score += 4;
            }
            if ($rule->product_origin !== null) {
                $score += 2;
            }
            if ($rule->recipient_type !== null) {
                $score += 2;
            }
            if ($rule->is_final_consumer !== null) {
                $score += 1;
            }
            if ($rule->is_interestadual !== null) {
                $score += 1;
            }
            return ['rule' => $rule, 'score' => $score];
        });

        // 4. Regra com maior score vence (desempate por priority DESC, id DESC)
        $winner = $scored->sort(function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($a['rule']->priority !== $b['rule']->priority) {
                return $b['rule']->priority <=> $a['rule']->priority;
            }
            return $b['rule']->id <=> $a['rule']->id;
        })->first();

        $rule = $winner['rule'];

        Log::debug('FiscalRuleResolver: Regra encontrada', [
            'rule_id' => $rule->id,
            'score'   => $winner['score']
        ]);

        return $rule;
    }
}
