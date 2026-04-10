<?php

namespace App\Services\Financial\BankStatement;

use App\Domain\DTO\Financial\BankStatementSuggestionDTO;
use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;

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

            usort($suggestions, fn (array $left, array $right) => $right['score'] <=> $left['score']);

            $limited = array_slice($suggestions, 0, 5);

            $line->update([
                'metadata' => array_merge($line->metadata ?? [], [
                    'suggestions' => $limited,
                    'suggested_at' => now()->toDateTimeString(),
                ]),
            ]);

            $this->setSuccess('Sugestoes de conciliacao geradas com sucesso.', [
                'suggestions' => $limited,
            ]);

            return $limited;
        } catch (\Throwable $e) {
            $this->setError('Erro ao gerar sugestoes de conciliacao.', [
                'exception' => [$e->getMessage()],
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestCashMovements(BankStatementLine $line): array
    {
        $date = Carbon::parse($line->transaction_date);

        return CashMovement::query()
            ->where('company_id', $line->company_id)
            ->where('financial_account_id', $line->financial_account_id)
            ->whereNull('reversal_of_id')
            ->whereBetween('transaction_date', [
                $date->copy()->subDays(7)->toDateString(),
                $date->copy()->addDays(7)->toDateString(),
            ])
            ->whereDoesntHave('statementLines', fn ($query) => $query->where('id', '!=', $line->id))
            ->get()
            ->map(function (CashMovement $movement) use ($line, $date) {
                $amountDiff = abs((float) $movement->amount - (float) $line->amount);
                $dateDiff = $date->diffInDays(Carbon::parse($movement->transaction_date));
                $score = 0;

                if ($movement->direction?->value === $line->direction()?->value) {
                    $score += 20;
                }

                $score += match (true) {
                    $amountDiff < 0.01 => 35,
                    $amountDiff <= 1 => 20,
                    $amountDiff <= 5 => 10,
                    default => 0,
                };

                $score += match (true) {
                    $dateDiff === 0 => 20,
                    $dateDiff <= 3 => 12,
                    $dateDiff <= 7 => 6,
                    default => 0,
                };

                $score += $this->tokenOverlapScore($line->description, $movement->description);

                if ($score < 25) {
                    return null;
                }

                return (new BankStatementSuggestionDTO(
                    originType: 'cash_movement',
                    originId: $movement->id,
                    score: $score,
                    label: sprintf(
                        '%s | %s | R$ %s | %s',
                        $movement->transaction_date?->format('d/m/Y'),
                        $movement->description,
                        number_format((float) $movement->amount, 2, ',', '.'),
                        $movement->origin_label
                    ),
                    reason: sprintf(
                        'Valor %s e data com diferenca de %s dia(s).',
                        $amountDiff < 0.01 ? 'igual' : 'proxima',
                        $dateDiff
                    ),
                    payload: [
                        'direction' => $movement->direction?->value,
                        'amount' => (float) $movement->amount,
                        'transaction_date' => $movement->transaction_date?->toDateString(),
                        'description' => $movement->description,
                        'origin_label' => $movement->origin_label,
                    ],
                ))->toArray();
            })
            ->filter()
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
        $date = Carbon::parse($line->transaction_date);

        return AccountPayableInstallment::query()
            ->with('accountPayable.supplier')
            ->where('company_id', $line->company_id)
            ->whereIn('status', [
                PayableStatus::PENDING->value,
                PayableStatus::PARTIALLY_PAID->value,
                PayableStatus::OVERDUE->value,
            ])
            ->where('balance_amount', '>', 0)
            ->whereBetween('due_date', [
                $date->copy()->subDays(30)->toDateString(),
                $date->copy()->addDays(30)->toDateString(),
            ])
            ->limit(30)
            ->get()
            ->map(function (AccountPayableInstallment $installment) use ($line, $date) {
                $score = $this->scoreInstallmentSuggestion(
                    lineDescription: $line->description,
                    lineAmount: (float) $line->amount,
                    lineDate: $date,
                    targetDate: Carbon::parse($installment->due_date),
                    targetAmount: (float) $installment->balance_amount,
                    referenceText: implode(' ', array_filter([
                        $installment->accountPayable?->description,
                        $installment->notes,
                        $installment->accountPayable?->supplier?->name,
                    ])),
                );

                if ($score < 25) {
                    return null;
                }

                return (new BankStatementSuggestionDTO(
                    originType: 'account_payable_installment',
                    originId: $installment->id,
                    score: $score,
                    label: sprintf(
                        'AP %s | %s | R$ %s',
                        $installment->sequence_number,
                        $installment->accountPayable?->supplier?->name ?? 'Sem fornecedor',
                        number_format((float) $installment->balance_amount, 2, ',', '.')
                    ),
                    reason: 'Parcela em aberto com valor e vencimento proximos ao extrato.',
                    payload: [
                        'due_date' => $installment->due_date?->toDateString(),
                        'balance_amount' => (float) $installment->balance_amount,
                        'description' => $installment->accountPayable?->description,
                        'partner_name' => $installment->accountPayable?->supplier?->name,
                    ],
                ))->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suggestReceivableInstallments(BankStatementLine $line): array
    {
        $date = Carbon::parse($line->transaction_date);

        return AccountReceivableInstallment::query()
            ->with('accountReceivable.customer')
            ->where('company_id', $line->company_id)
            ->whereIn('status', [
                ReceivableStatus::PENDING->value,
                ReceivableStatus::PARTIALLY_RECEIVED->value,
                ReceivableStatus::OVERDUE->value,
            ])
            ->where('balance_amount', '>', 0)
            ->whereBetween('due_date', [
                $date->copy()->subDays(30)->toDateString(),
                $date->copy()->addDays(30)->toDateString(),
            ])
            ->limit(30)
            ->get()
            ->map(function (AccountReceivableInstallment $installment) use ($line, $date) {
                $score = $this->scoreInstallmentSuggestion(
                    lineDescription: $line->description,
                    lineAmount: (float) $line->amount,
                    lineDate: $date,
                    targetDate: Carbon::parse($installment->due_date),
                    targetAmount: (float) $installment->balance_amount,
                    referenceText: implode(' ', array_filter([
                        $installment->accountReceivable?->description,
                        $installment->notes,
                        $installment->accountReceivable?->customer?->name,
                    ])),
                );

                if ($score < 25) {
                    return null;
                }

                return (new BankStatementSuggestionDTO(
                    originType: 'account_receivable_installment',
                    originId: $installment->id,
                    score: $score,
                    label: sprintf(
                        'AR %s | %s | R$ %s',
                        $installment->sequence_number,
                        $installment->accountReceivable?->customer?->name ?? 'Sem cliente',
                        number_format((float) $installment->balance_amount, 2, ',', '.')
                    ),
                    reason: 'Parcela em aberto com valor e vencimento proximos ao extrato.',
                    payload: [
                        'due_date' => $installment->due_date?->toDateString(),
                        'balance_amount' => (float) $installment->balance_amount,
                        'description' => $installment->accountReceivable?->description,
                        'partner_name' => $installment->accountReceivable?->customer?->name,
                    ],
                ))->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }

    private function scoreInstallmentSuggestion(
        string $lineDescription,
        float $lineAmount,
        Carbon $lineDate,
        Carbon $targetDate,
        float $targetAmount,
        string $referenceText,
    ): int {
        $amountDiff = abs($targetAmount - $lineAmount);
        $dateDiff = $lineDate->diffInDays($targetDate);
        $score = 0;

        $score += match (true) {
            $amountDiff < 0.01 => 35,
            $amountDiff <= 1 => 20,
            $amountDiff <= 5 => 12,
            default => 0,
        };

        $score += match (true) {
            $dateDiff === 0 => 20,
            $dateDiff <= 5 => 12,
            $dateDiff <= 15 => 6,
            default => 0,
        };

        return $score + $this->tokenOverlapScore($lineDescription, $referenceText);
    }

    private function tokenOverlapScore(?string $left, ?string $right): int
    {
        $leftTokens = $this->tokenize($left);
        $rightTokens = $this->tokenize($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0;
        }

        $matches = count(array_intersect($leftTokens, $rightTokens));

        return min($matches * 4, 20);
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(?string $text): array
    {
        if (! is_string($text) || trim($text) === '') {
            return [];
        }

        $normalized = mb_strtolower(preg_replace('/[^[:alnum:]\s]/u', ' ', $text) ?? '');
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) >= 3));
    }
}
