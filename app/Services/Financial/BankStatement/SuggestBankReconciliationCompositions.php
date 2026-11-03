<?php

namespace App\Services\Financial\BankStatement;

use Illuminate\Support\Collection;

class SuggestBankReconciliationCompositions
{
    public function suggest(Collection $lines): array
    {
        $first = $lines->first();
        $total = (int) $lines->sum(fn ($line) => round((float) $line->amount * 100));
        $reference = clone $first;
        $reference->amount = $total / 100;
        $names = $lines->map(fn ($line) => data_get($line->metadata, 'raw.name'))->filter()->unique();
        $reference->description = $lines->pluck('description')->implode(' ').' '.$names->implode(' ');
        $reference->metadata = array_merge($reference->metadata ?? [], ['raw' => [
            'name' => $names->count() === 1 ? $names->first() : null,
            'memo' => $lines->map(fn ($line) => data_get($line->metadata, 'raw.memo'))->filter()->implode(' '),
        ]]);
        $eligibility = app(BankStatementCandidateEligibilityService::class);
        $presenter = app(BankReconciliationCandidatePresenter::class);
        $scorer = app(BankStatementMatchScorer::class);
        $datePolicy = app(BankStatementDatePolicy::class);
        $bundles = [];
        $truncated = false;

        foreach (['cash_movement', $first->isInflow() ? 'receivable' : 'payable'] as $type) {
            $movement = $type === 'cash_movement';
            $dateColumn = $movement ? 'transaction_date' : 'due_date';
            $amountColumn = $movement ? 'amount' : 'balance_amount';
            $query = $movement ? $eligibility->movementsForLine($first)->with([
                'counterpartyPartner', 'payablePayment.installment.accountPayable.supplier', 'receivablePayment.installment.accountReceivable.customer',
            ]) : $eligibility->installmentsForLine($first)->with($type === 'receivable' ? 'accountReceivable.customer' : 'accountPayable.supplier');
            $days = $datePolicy->windowDays($first, $movement);
            $query->where($amountColumn, '>', 0)->whereBetween($dateColumn, [
                $lines->min('transaction_date')->copy()->subDays($days)->toDateString(),
                $lines->max('transaction_date')->copy()->addDays($days)->toDateString(),
            ]);
            $candidates = $query->orderBy('id')->lazy(200)->map(function ($record) use ($scorer, $reference, $presenter, $lines, $type): array {
                $assessment = $scorer->assess($reference, $record);

                return [
                    ...$presenter->present($record, $lines, $type),
                    'assessment' => $assessment,
                    'identity_score' => $assessment['points']['tax_document'] + $assessment['points']['reference'] + $assessment['points']['counterparty_name'],
                ];
            })->filter(fn (array $candidate): bool => $candidate['assessment']['conflicts'] === []);
            $pool = collect($candidates->all())->sort(fn (array $a, array $b) => ($b['identity_score'] <=> $a['identity_score'])
                ?: (abs($a['amount_cents'] - $total) <=> abs($b['amount_cents'] - $total)) ?: ($a['id'] <=> $b['id']));
            $limit = max(1, min(60, (int) config('bank_reconciliation.suggestions.composition_candidate_limit', 30)));
            $truncated = $truncated || $pool->count() > $limit;
            $pool = $pool->take($limit)->values()->all();

            foreach ($pool as $candidate) {
                $amount = $candidate['amount_cents'];
                if ($lines->count() > 1 && $amount === $total) {
                    $bundles[] = $this->bundle($type, 'aggregate', [$candidate], $total, 'Vários lançamentos para um registro', 'O registro corresponde ao total dos lançamentos bancários selecionados.');
                }
                if (! $movement && $amount > $total && $candidate['identity_score'] >= 25) {
                    $bundles[] = $this->bundle($type, 'partial', [$candidate], $total, 'Baixa parcial', sprintf('Baixar R$ %s e manter R$ %s em aberto.', $this->money($total), $this->money($amount - $total)));
                }
                $difference = $total - $amount;
                $basisPoints = max(0, (int) config('bank_reconciliation.suggestions.relative_amount_window_basis_points', 200));
                $window = min((int) config('bank_reconciliation.suggestions.maximum_amount_window_cents', 5000), max((int) config('bank_reconciliation.suggestions.minimum_amount_window_cents', 500), intdiv($total * $basisPoints + 9999, 10000)));
                if (! $movement && $difference !== 0 && abs($difference) <= $window && $candidate['identity_score'] >= 25) {
                    $bundle = $this->bundle($type, 'adjustment', [$candidate], $total, 'Conferir ajustes da parcela', sprintf('Diferença de R$ %s. %s Defina os ajustes antes de confirmar.', $this->money(abs($difference)), $difference > 0 ? 'Pode corresponder a juros, multa ou outro acréscimo.' : 'Pode corresponder a desconto ou tarifa retida; confira a natureza da diferença.'));
                    $bundle['items'][0]['adjustment_expected_cents'] = $difference;
                    $bundles[] = $bundle;
                }
            }

            $eligibleForSum = array_values(array_filter($pool, fn ($candidate) => $candidate['amount_cents'] < $total));
            $maxSize = max(2, min(3, (int) config('bank_reconciliation.suggestions.maximum_composition_size', 3)));
            foreach ($this->combinations($eligibleForSum, $total, $maxSize) as $items) {
                $bundles[] = $this->bundle($type, 'combination', $items, $total, 'Composição de '.count($items).' registros', 'A soma dos registros fecha exatamente o valor bancário. Confira as contrapartes e as datas.');
            }
        }

        $result = collect($bundles)->sortByDesc('score');
        $combinationCount = $result->where('kind', 'combination')->count();

        return [
            'bundles' => $result->take(max(1, (int) config('bank_reconciliation.suggestions.maximum_advanced_suggestions', 5)))->map(function (array $bundle) use ($combinationCount): array {
                $bundle['ambiguous'] = $bundle['kind'] === 'combination' && $combinationCount > 1;
                $bundle['confidence_label'] = $bundle['score'] >= 85 ? 'Média confiança' : 'Baixa confiança';

                return $bundle;
            })->values()->all(),
            'truncated' => $truncated,
        ];
    }

    private function combinations(array $pool, int $target, int $maximum, array $selected = [], int $offset = 0): \Generator
    {
        if ($target === 0) {
            if (count($selected) >= 2) {
                yield $selected;
            }

            return;
        }
        if (count($selected) >= $maximum) {
            return;
        }
        for ($index = $offset; $index < count($pool); $index++) {
            if ($pool[$index]['amount_cents'] <= $target) {
                yield from $this->combinations($pool, $target - $pool[$index]['amount_cents'], $maximum, [...$selected, $pool[$index]], $index + 1);
            }
        }
    }

    private function bundle(string $type, string $kind, array $items, int $total, string $label, string $reason): array
    {
        return [
            'key' => $type.':'.$kind.':'.implode('-', array_column($items, 'id')),
            'type' => $type, 'kind' => $kind, 'label' => $label, 'reason' => $reason,
            'origin_label' => match ($type) {
                'cash_movement' => 'Movimentos já lançados — apenas vincular',
                'receivable' => 'Contas a receber — registrar recebimentos',
                default => 'Contas a pagar — registrar pagamentos',
            },
            'score' => (int) round(array_sum(array_column(array_column($items, 'assessment'), 'score')) / count($items)) + ($kind === 'combination' ? 35 : 0),
            'total_cents' => $total,
            'items' => array_map(fn ($item) => [
                ...$item,
                'suggested_amount' => ($kind === 'partial' || $kind === 'adjustment' ? $total : $item['amount_cents']) / 100,
            ], $items),
            'requires_review' => true,
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
