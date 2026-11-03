<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankStatementLine;
use App\Models\CashMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BankStatementMovementEligibilityService
{
    public function queryForLine(BankStatementLine $line, bool $forSuggestions = false): Builder
    {
        $eligibility = app(BankStatementCandidateEligibilityService::class);
        $query = $eligibility->movementsForLine($line);
        if ($forSuggestions) {
            return $eligibility->applySuggestionWindow($query, $line, movement: true);
        }

        [$amountTolerance, $dateToleranceDays] = $this->tolerancesFor($line);
        $transactionDate = $line->transaction_date;
        $amount = (int) round((float) $line->amount * 100);
        $tolerance = (int) round($amountTolerance * 100);

        return $query
            ->whereBetween('amount', [
                max(0, $amount - $tolerance),
                $amount + $tolerance,
            ])
            ->whereBetween('transaction_date', [
                $transactionDate?->copy()->subDays($dateToleranceDays)->toDateString(),
                $transactionDate?->copy()->addDays($dateToleranceDays)->toDateString(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public function assertEligible(BankStatementLine $line, CashMovement $movement, ?string $exceptionReason = null): array
    {
        $errors = [];

        if ((int) $movement->company_id !== (int) $line->company_id) {
            $errors['cash_movement_id'][] = 'Movimento financeiro não pertence à empresa da linha do extrato.';
        }

        if ((int) $movement->financial_account_id !== (int) $line->financial_account_id) {
            $errors['cash_movement_id'][] = 'Movimento financeiro não pertence à conta da linha do extrato.';
        }

        if ($movement->direction?->value !== $line->direction()?->value) {
            $errors['cash_movement_id'][] = 'A direção do movimento deve ser igual à direção da linha do extrato.';
        }

        if ($movement->reversed_at || $movement->reversal_of_id) {
            $errors['cash_movement_id'][] = 'Movimento estornado não pode ser conciliado.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $exceptions = $this->exceptionsFor($line, $movement);

        if ($exceptions !== [] && blank($exceptionReason)) {
            throw ValidationException::withMessages([
                'exception_reason' => ['Informe uma justificativa para conciliar fora da margem configurada.'],
            ]);
        }

        return $exceptions;
    }

    /**
     * @return array<int, string>
     */
    public function exceptionsFor(BankStatementLine $line, CashMovement $movement): array
    {
        [$amountTolerance, $dateToleranceDays] = $this->tolerancesFor($line);
        $exceptions = [];
        $amountDifference = abs((int) round((float) $movement->amount * 100) - (int) round((float) $line->amount * 100));
        $amountToleranceCents = (int) round($amountTolerance * 100);
        $dateDifference = abs($line->transaction_date?->diffInDays($movement->transaction_date) ?? 0);

        if ($amountDifference > $amountToleranceCents) {
            $exceptions[] = sprintf(
                'Diferença de valor de R$ %s excede a margem de R$ %s.',
                number_format($amountDifference / 100, 2, ',', '.'),
                number_format($amountTolerance, 2, ',', '.')
            );
        }

        if ($dateDifference > $dateToleranceDays) {
            $exceptions[] = sprintf(
                'Diferença de data de %d dia(s) excede a margem de %d dia(s).',
                $dateDifference,
                $dateToleranceDays
            );
        }

        return $exceptions;
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function tolerancesFor(BankStatementLine $line): array
    {
        $account = $line->financialAccount;

        return [
            max(0, (float) ($account?->reconciliation_amount_tolerance ?? 0.05)),
            max(0, (int) ($account?->reconciliation_date_tolerance_days ?? 3)),
        ];
    }
}
