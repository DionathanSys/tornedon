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

    private function assertDirection(BankStatementLine $line): void
    {
        if ($line->direction() === null) {
            throw ValidationException::withMessages([
                'selection' => 'A direção do lançamento bancário é inválida.',
            ]);
        }
    }
}
