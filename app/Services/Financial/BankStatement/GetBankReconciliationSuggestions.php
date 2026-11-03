<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankStatementLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GetBankReconciliationSuggestions
{
    public function get(int $companyId, Collection $selectedLines): array
    {
        $ids = $selectedLines->pluck('id')->unique()->all();
        $lines = BankStatementLine::query()->where('company_id', $companyId)
            ->whereIn('id', $ids)->with('financialAccount')->orderBy('id')->get();
        $first = $lines->first();
        if (! $first || $lines->count() !== count($ids) || $lines->contains(fn (BankStatementLine $line) => $line->financial_account_id !== $first->financial_account_id
            || $line->direction() === null || $line->direction() !== $first->direction()
            || $line->reconciliation_status?->canResolve() !== true || $line->hasFinancialEffect())) {
            throw ValidationException::withMessages([
                'selection' => 'Selecione lançamentos pendentes da empresa atual, da mesma conta e direção, sem vínculo financeiro.',
            ]);
        }

        $generator = app(SuggestBankStatementMatchesService::class);
        $eligibility = app(BankStatementCandidateEligibilityService::class);
        $presenter = app(BankReconciliationCandidatePresenter::class);
        $recommendations = [];
        $failed = 0;

        foreach ($lines as $line) {
            // Opening the workbench also discovers records created since the OFX import.
            $suggestions = $generator->suggestForLine($line);
            if ($generator->hasError()) {
                $failed++;

                continue;
            }

            foreach ($suggestions as $suggestion) {
                $type = match ($suggestion['origin_type']) {
                    'cash_movement' => 'cash_movement',
                    'account_receivable_installment' => 'receivable',
                    'account_payable_installment' => 'payable',
                };
                $movement = $type === 'cash_movement';
                $query = $movement
                    ? $eligibility->movementsForLine($line)
                    : $eligibility->installmentsForLine($line)->with($type === 'receivable' ? 'accountReceivable.customer' : 'accountPayable.supplier');
                $record = $query->find($suggestion['origin_id']);
                if (! $record) {
                    continue;
                }

                $presented = $presenter->present($record, $lines, $type);
                $amountDifference = abs($presented['amount_cents'] - (int) round((float) $line->amount * 100));
                $days = (int) $line->transaction_date->diffInDays($record->{$movement ? 'transaction_date' : 'due_date'});
                $dateReason = $days === 0 ? 'na mesma data do extrato' : sprintf('%d dia(s) %s da data do extrato', abs($days), $days < 0 ? 'antes' : 'depois');
                $key = $type.':'.$record->id;
                $matchingIds = array_unique([...($recommendations[$key]['statement_line_ids'] ?? []), $line->id]);

                if (! isset($recommendations[$key]) || $suggestion['score'] > $recommendations[$key]['score']) {
                    $recommendations[$key] = [
                        ...$presented,
                        'type' => $type,
                        'score' => $suggestion['score'],
                        'assessment' => $suggestion['assessment'],
                        'origin_label' => match ($type) {
                            'cash_movement' => 'Movimento já lançado',
                            'receivable' => 'Conta a receber em aberto',
                            'payable' => 'Conta a pagar em aberto',
                        },
                        'reason' => sprintf('%s; %s %s. Identificação: %s.',
                            $amountDifference === 0 ? 'Mesmo valor' : 'Diferença de R$ '.number_format($amountDifference / 100, 2, ',', '.'),
                            $movement ? 'lançamento' : 'vencimento',
                            $dateReason,
                            $presented['party'] ?: 'não informada',
                        ),
                        'bank_reference' => sprintf('Extrato #%d · %s · R$ %s', $line->id, $line->transaction_date->format('d/m/Y'), number_format((float) $line->amount, 2, ',', '.')),
                    ];
                }

                $recommendations[$key]['statement_line_ids'] = array_values($matchingIds);
            }
        }

        return [
            'records' => collect($recommendations)->sortByDesc('score')->take(5)->map(function (array $recommendation): array {
                $assessment = $recommendation['assessment'];
                if (count($recommendation['statement_line_ids']) > 1) {
                    $assessment['ambiguous'] = true;
                    $assessment['requires_review'] = true;
                    $assessment['evidence'][] = 'Mesmo registro compatível com mais de um lançamento do extrato.';
                    if ($assessment['confidence'] === 'high') {
                        $assessment['confidence'] = 'medium';
                    }
                }

                return [
                    ...$recommendation,
                    'assessment' => $assessment,
                    'confidence' => $assessment['confidence'],
                    'confidence_label' => match ($assessment['confidence']) {
                        'high' => 'Alta confiança',
                        'medium' => 'Média confiança',
                        default => 'Baixa confiança',
                    },
                    'ambiguous' => $assessment['ambiguous'],
                    'requires_review' => $assessment['requires_review'],
                    'evidence' => $assessment['evidence'],
                ];
            })->values()->all(),
            'failed_lines' => $failed,
        ];
    }
}
