<?php

namespace App\Services\Fiscal;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
use App\Models\FiscalRule;

class RuleMatcher
{
    /**
     * Verifica se uma regra fiscal corresponde ao contexto informado.
     *
     * Todas as condições são avaliadas com AND.
     * Se o valor da condição for um array, avalia com OR (any of).
     * Se a chave terminar com "_prefix", avalia com str_starts_with.
     */
    public function matches(FiscalRule $rule, FiscalContextDTO $context): bool
    {
        $conditions = $rule->conditions ?? [];

        if (empty($conditions)) {
            return false;
        }

        $contextArray = $context->toArray();

        foreach ($conditions as $field => $expected) {
            if (!$this->evaluateCondition($field, $expected, $contextArray)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filtra uma coleção de regras e retorna a primeira que dá match.
     *
     * @param  iterable<FiscalRule>  $rules
     */
    public function findFirstMatch(iterable $rules, FiscalContextDTO $context): ?FiscalRule
    {
        foreach ($rules as $rule) {
            if ($this->matches($rule, $context)) {
                return $rule;
            }
        }

        return null;
    }

    private function evaluateCondition(string $field, mixed $expected, array $contextArray): bool
    {
        // Campos com sufixo _prefix fazem match por início de string
        if (str_ends_with($field, '_prefix')) {
            $realField = str_replace('_prefix', '', $field);
            $actual = $contextArray[$realField] ?? null;

            if ($actual === null) {
                return false;
            }

            if (is_array($expected)) {
                foreach ($expected as $prefix) {
                    if (str_starts_with((string) $actual, (string) $prefix)) {
                        return true;
                    }
                }

                return false;
            }

            return str_starts_with((string) $actual, (string) $expected);
        }

        $actual = $contextArray[$field] ?? null;

        // Boolean
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }

        // Array = any of (OR)
        if (is_array($expected)) {
            return in_array($actual, $expected, false);
        }

        // Exact match
        return (string) $actual === (string) $expected;
    }
}
