<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankStatementLine;
use App\Models\CashMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BankStatementMovementEligibilityService
{
    public function queryForLine(BankStatementLine $line): Builder
    {
        [$amountTolerance, $dateToleranceDays] = $this->tolerancesFor($line);
        $transactionDate = $line->transaction_date;

        return CashMovement::query()
            ->where('company_id', $line->company_id)
            ->where('financial_account_id', $line->financial_account_id)
            ->where('direction', $line->direction()?->value)
            ->whereNull('reversed_at')
            ->whereNull('reversal_of_id')
            ->whereBetween('amount', [
                max(0, ((float) $line->amount - $amountTolerance) * 100),
                ((float) $line->amount + $amountTolerance) * 100,
            ])
            ->whereBetween('transaction_date', [
                $transactionDate?->copy()->subDays($dateToleranceDays)->toDateString(),
                $transactionDate?->copy()->addDays($dateToleranceDays)->toDateString(),
            ])
            ->whereDoesntHave('statementLines', fn (Builder $query) => $query->where('id', '!=', $line->id));
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

        [$amountTolerance, $dateToleranceDays] = $this->tolerancesFor($line);
        $exceptions = [];
        $amountDifference = abs((float) $movement->amount - (float) $line->amount);
        $dateDifference = $line->transaction_date?->diffInDays($movement->transaction_date) ?? 0;

        if ($amountDifference > $amountTolerance) {
            $exceptions[] = sprintf(
                'Diferença de valor de R$ %s excede a margem de R$ %s.',
                number_format($amountDifference, 2, ',', '.'),
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

        if ($exceptions !== [] && blank($exceptionReason)) {
            throw ValidationException::withMessages([
                'exception_reason' => ['Informe uma justificativa para conciliar fora da margem configurada.'],
            ]);
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
