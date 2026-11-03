<?php

namespace App\Services\Financial\BankStatement;

use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BankStatementCandidateEligibilityService
{
    public function movementsForLine(BankStatementLine $line): Builder
    {
        $this->assertDirection($line);

        return CashMovement::query()
            ->where('company_id', $line->company_id)
            ->where('financial_account_id', $line->financial_account_id)
            ->where('direction', $line->direction()->value)
            ->whereNull('reversed_at')
            ->whereNull('reversal_of_id')
            ->unreconciled();
    }

    public function installmentsForLine(BankStatementLine $line): Builder
    {
        $this->assertDirection($line);
        $outflow = $line->isOutflow();
        $model = $outflow ? AccountPayableInstallment::class : AccountReceivableInstallment::class;
        $parent = $outflow ? 'accountPayable' : 'accountReceivable';
        $statuses = $outflow
            ? [PayableStatus::PENDING, PayableStatus::PARTIALLY_PAID, PayableStatus::OVERDUE]
            : [ReceivableStatus::PENDING, ReceivableStatus::PARTIALLY_RECEIVED, ReceivableStatus::OVERDUE];

        return $model::query()
            ->considered()
            ->where('company_id', $line->company_id)
            ->whereIn('status', $statuses)
            ->where('balance_amount', '>', 0)
            ->whereHas($parent, fn (Builder $query) => $query
                ->where('company_id', $line->company_id)
                ->where('status', '!=', 'cancelled'));
    }

    public function applySuggestionWindow(Builder $query, BankStatementLine $line, bool $movement): Builder
    {
        $amount = (int) round((float) $line->amount * 100);
        $basisPoints = max(0, (int) config('bank_reconciliation.suggestions.relative_amount_window_basis_points', 200));
        $minimum = max(0, (int) config('bank_reconciliation.suggestions.minimum_amount_window_cents', 500));
        $maximum = max($minimum, (int) config('bank_reconciliation.suggestions.maximum_amount_window_cents', 5000));
        $amountWindow = max(
            (int) round((float) ($line->financialAccount?->reconciliation_amount_tolerance ?? 0.05) * 100),
            min($maximum, max($minimum, intdiv($amount * $basisPoints + 9999, 10000))),
        );
        $days = $movement
            ? max((int) ($line->financialAccount?->reconciliation_date_tolerance_days ?? 3), (int) config('bank_reconciliation.suggestions.movement_date_window_days', 7))
            : (int) config('bank_reconciliation.suggestions.installment_date_window_days', 60);
        $date = $line->transaction_date;

        return $query
            ->whereBetween($movement ? 'amount' : 'balance_amount', [max(1, $amount - $amountWindow), $amount + $amountWindow])
            ->whereBetween($movement ? 'transaction_date' : 'due_date', [
                $date->copy()->subDays(max(0, $days))->toDateString(),
                $date->copy()->addDays(max(0, $days))->toDateString(),
            ]);
    }

    private function assertDirection(BankStatementLine $line): void
    {
        if ($line->direction() === null) {
            throw ValidationException::withMessages([
                'selection' => 'A direção do lançamento bancário é inválida.',
            ]);
        }
    }
}
