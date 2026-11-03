<?php

namespace App\Services\Financial\BankStatement;

use App\Domain\DTO\Financial\BankStatementSuggestionDTO;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class SuggestBankStatementMatchesService
{
    use HandlesServiceResponse;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestForLine(BankStatementLine $line): array
    {
        $this->resetResponse();

        try {
            $suggestions = [
                ...$this->suggestCashMovements($line),
                ...$this->suggestInstallments($line),
            ];

            usort($suggestions, fn (array $left, array $right) => ($right['score'] <=> $left['score'])
                ?: strcmp($left['origin_type'], $right['origin_type'])
                ?: ($left['origin_id'] <=> $right['origin_id']));

            foreach ($suggestions as $index => $suggestion) {
                $gaps = [];
                foreach ($suggestions as $otherIndex => $other) {
                    if ($index !== $otherIndex) {
                        $gaps[] = abs($suggestion['score'] - $other['score']);
                    }
                }
                $gap = $gaps === [] ? null : min($gaps);
                $suggestion['assessment']['score_gap'] = $gap;
                if ($gap !== null && $gap < max(1, (int) config('bank_reconciliation.suggestions.ambiguity_score_gap', 10))) {
                    $suggestion['assessment']['ambiguous'] = true;
                    $suggestion['assessment']['requires_review'] = true;
                    if ($suggestion['assessment']['confidence'] === 'high') {
                        $suggestion['assessment']['confidence'] = 'medium';
                    }
                    $suggestion['assessment']['evidence'][] = 'Há outra opção com pontuação próxima.';
                    $suggestion['reason'] .= ' Há outra opção com pontuação próxima.';
                }
                $suggestions[$index] = $suggestion;
            }

            $limited = array_slice($suggestions, 0, 5);

            $line->update([
                'metadata' => array_merge($line->metadata ?? [], [
                    'suggestions' => $limited,
                    'suggested_at' => now()->toDateTimeString(),
                    'suggestions_version' => BankStatementLine::SUGGESTIONS_VERSION,
                    'suggestions_status' => $limited === [] ? 'empty' : 'ready',
                    'suggestions_attempted_at' => now()->toDateTimeString(),
                    'suggestions_error_code' => null,
                ]),
            ]);

            $this->setSuccess($limited === [] ? 'Nenhuma correspondência encontrada para esta linha.' : 'Sugestões atualizadas.', [
                'suggestions' => $limited,
            ]);

            return $limited;
        } catch (\Throwable $e) {
            $this->setError('Erro ao gerar sugestões de conciliação.', [
                'suggestions' => ['Não foi possível atualizar as sugestões. Tente novamente.'],
            ]);

            Log::error('Erro ao gerar sugestões de conciliação.', [
                'bank_statement_line_id' => $line->id,
                'company_id' => $line->company_id,
                'error_code' => $this->getErrorCode(),
                'exception' => $e,
            ]);

            try {
                $line->update([
                    'metadata' => array_merge($line->metadata ?? [], [
                        'suggestions_status' => 'error',
                        'suggestions_attempted_at' => now()->toDateTimeString(),
                        'suggestions_error_code' => $this->getErrorCode(),
                    ]),
                ]);
            } catch (\Throwable $metadataException) {
                Log::error('Não foi possível registrar a falha das sugestões.', [
                    'bank_statement_line_id' => $line->id,
                    'exception' => $metadataException,
                ]);
            }

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestCashMovements(BankStatementLine $line): array
    {
        return app(BankStatementMovementEligibilityService::class)
            ->queryForLine($line, forSuggestions: true)
            ->with([
                'counterpartyPartner',
                'payablePayment.installment.accountPayable.supplier',
                'receivablePayment.installment.accountReceivable.customer',
            ])
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (CashMovement $movement) => $this->makeSuggestion($line, $movement, 'cash_movement'))
            ->filter()
            ->sortByDesc('score')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestInstallments(BankStatementLine $line): array
    {
        return $line->isOutflow()
            ? $this->suggestPayableInstallments($line)
            : $this->suggestReceivableInstallments($line);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestPayableInstallments(BankStatementLine $line): array
    {
        $eligibility = app(BankStatementCandidateEligibilityService::class);

        return $eligibility->applySuggestionWindow($eligibility->installmentsForLine($line), $line, movement: false)
            ->with('accountPayable.supplier')
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (AccountPayableInstallment $installment) => $this->makeSuggestion($line, $installment, 'account_payable_installment'))
            ->filter()
            ->sortByDesc('score')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestReceivableInstallments(BankStatementLine $line): array
    {
        $eligibility = app(BankStatementCandidateEligibilityService::class);

        return $eligibility->applySuggestionWindow($eligibility->installmentsForLine($line), $line, movement: false)
            ->with('accountReceivable.customer')
            ->orderBy('id')
            ->lazy(200)
            ->map(fn (AccountReceivableInstallment $installment) => $this->makeSuggestion($line, $installment, 'account_receivable_installment'))
            ->filter()
            ->sortByDesc('score')
            ->take(6)
            ->values()
            ->all();
    }

    private function makeSuggestion(BankStatementLine $line, CashMovement|AccountPayableInstallment|AccountReceivableInstallment $candidate, string $originType): ?array
    {
        $assessment = app(BankStatementMatchScorer::class)->assess($line, $candidate);
        if ($assessment['score'] < (int) config('bank_reconciliation.suggestions.minimum_score', 25)) {
            return null;
        }
        $movement = $candidate instanceof CashMovement;
        $parent = $candidate instanceof AccountPayableInstallment ? $candidate->accountPayable : ($candidate instanceof AccountReceivableInstallment ? $candidate->accountReceivable : null);
        $amount = (float) $candidate->{$movement ? 'amount' : 'balance_amount'};
        $party = $movement ? $candidate->counterparty_label : $parent?->counterparty_label;

        return (new BankStatementSuggestionDTO(
            originType: $originType,
            originId: $candidate->id,
            score: $assessment['score'],
            label: sprintf('%s | %s | R$ %s',
                $movement ? $candidate->transaction_date->format('d/m/Y') : ($candidate instanceof AccountPayableInstallment ? 'AP ' : 'AR ').$candidate->sequence_number,
                $party && $party !== 'Não informado' ? $party : ($candidate->description ?: $parent?->description),
                number_format($amount, 2, ',', '.'),
            ),
            reason: sprintf('Valor %s e data com diferenca de %d dia(s). %s',
                $assessment['amount_difference_cents'] === 0 ? 'igual' : 'próximo',
                $assessment['date_difference_days'],
                implode(' ', $assessment['evidence']),
            ),
            payload: $movement ? [
                'direction' => $candidate->direction->value,
                'amount' => $amount,
                'transaction_date' => $candidate->transaction_date->toDateString(),
                'description' => $candidate->description,
                'origin_label' => $candidate->origin_label,
            ] : [
                'due_date' => $candidate->due_date->toDateString(),
                'balance_amount' => $amount,
                'description' => $parent?->description,
                'partner_name' => $party,
            ],
            assessment: $assessment,
        ))->toArray();
    }
}
