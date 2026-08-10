<?php

namespace App\Services\Financial\Dre;

use App\DTO\Financial\DreLineResultDTO;
use App\DTO\Financial\DreReportDTO;
use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreMode;
use App\Enum\Financial\DreOperation;
use App\Enum\Financial\DreView;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\DreLine;
use App\Models\DreModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateDreReportService
{
    /**
     * @param  array<int, int>  $companyIds
     */
    public function generate(
        DreModel $dreModel,
        array $companyIds,
        string $startDate,
        string $endDate,
        DreMode $mode = DreMode::COMPETENCE,
        DreView $view = DreView::PROJECTED_AND_REALIZED,
        ?int $costCenterId = null,
        ?int $resultCenterId = null,
    ): DreReportDTO {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));

        if ($companyIds === []) {
            throw ValidationException::withMessages([
                'company_ids' => ['Informe ao menos uma empresa.'],
            ]);
        }

        if (! in_array((int) $dreModel->company_id, $companyIds, true)) {
            throw ValidationException::withMessages([
                'dre_model_id' => ['O modelo DRE deve pertencer a uma das empresas selecionadas.'],
            ]);
        }

        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->endOfDay();
        $lines = $dreModel->lines()
            ->with(['chartAccounts', 'children'])
            ->get();
        $amountsByLine = $this->amountsByLine($lines, $companyIds, $start->toDateString(), $end->toDateString(), $mode, $view, $costCenterId, $resultCenterId);
        $revenueBase = max(abs((float) $amountsByLine->filter(fn (float $amount): bool => $amount > 0)->sum()), 0.0);
        $tree = $this->buildLineTree($lines, $amountsByLine, $revenueBase);

        return new DreReportDTO(
            dreModelId: (int) $dreModel->id,
            companyIds: $companyIds,
            startDate: $start,
            endDate: $end,
            mode: $mode->value,
            view: $view->value,
            lines: $tree,
        );
    }

    /**
     * @param  Collection<int, DreLine>  $lines
     * @param  array<int, int>  $companyIds
     * @return Collection<int, float>
     */
    private function amountsByLine(Collection $lines, array $companyIds, string $startDate, string $endDate, DreMode $mode, DreView $view, ?int $costCenterId, ?int $resultCenterId): Collection
    {
        return $lines->mapWithKeys(function (DreLine $line) use ($companyIds, $startDate, $endDate, $mode, $view, $costCenterId, $resultCenterId): array {
            if ($line->line_type !== DreLineType::ACCOUNT_GROUP) {
                return [$line->id => 0.0];
            }

            $accountIds = $this->lineChartAccountIds($line);
            if ($accountIds === []) {
                return [$line->id => 0.0];
            }

            $amount = $mode === DreMode::CASH
                ? $this->cashAmount($accountIds, $companyIds, $startDate, $endDate, $view, $costCenterId, $resultCenterId)
                : $this->competenceAmount($accountIds, $companyIds, $startDate, $endDate, $view, $costCenterId, $resultCenterId);

            $operation = $line->operation instanceof DreOperation ? $line->operation : DreOperation::ADD;

            return [$line->id => round($amount * $operation->multiplier(), 2)];
        });
    }

    /**
     * @return array<int, int>
     */
    private function lineChartAccountIds(DreLine $line): array
    {
        $ids = [];

        foreach ($line->chartAccounts as $account) {
            $ids[] = (int) $account->id;

            if (! (bool) $account->pivot->include_descendants) {
                continue;
            }

            foreach ($account->descendants() as $descendant) {
                $ids[] = (int) $descendant->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $companyIds
     */
    private function competenceAmount(array $accountIds, array $companyIds, string $startDate, string $endDate, DreView $view, ?int $costCenterId, ?int $resultCenterId): float
    {
        $payables = DB::table('account_payable_installments')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('competence_date', [$startDate, $endDate])
            ->where('status', '!=', PayableStatus::CANCELLED->value)
            ->when($view === DreView::REALIZED, fn ($query) => $query->whereIn('status', [PayableStatus::PAID->value, PayableStatus::PARTIALLY_PAID->value]))
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum($view === DreView::REALIZED ? 'paid_amount' : 'due_amount');

        $receivables = DB::table('account_receivable_installments')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('competence_date', [$startDate, $endDate])
            ->where('status', '!=', ReceivableStatus::CANCELLED->value)
            ->when($view === DreView::REALIZED, fn ($query) => $query->whereIn('status', [ReceivableStatus::RECEIVED->value, ReceivableStatus::PARTIALLY_RECEIVED->value]))
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum($view === DreView::REALIZED ? 'received_amount' : 'due_amount');

        $manualMovements = $this->manualCashMovementAmount($accountIds, $companyIds, 'competence_date', $startDate, $endDate, $costCenterId, $resultCenterId);

        return round((((float) $receivables - (float) $payables) / 100) + $manualMovements, 2);
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $companyIds
     */
    private function cashAmount(array $accountIds, array $companyIds, string $startDate, string $endDate, DreView $view, ?int $costCenterId, ?int $resultCenterId): float
    {
        $movements = DB::table('cash_movements')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->whereNull('reversal_of_id')
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->where(function ($query): void {
                $query->where('origin_type', 'manual')
                    ->orWhere('origin_type', AccountPayableInstallmentPayment::class)
                    ->orWhere('origin_type', AccountReceivableInstallmentPayment::class);
            })
            ->get(['direction', 'amount'])
            ->sum(fn (object $movement): float => ((string) $movement->direction === CashMovementDirection::INFLOW->value ? 1 : -1) * ((float) $movement->amount / 100));

        if ($view === DreView::REALIZED) {
            return round((float) $movements, 2);
        }

        $openPayables = DB::table('account_payable_installments')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->whereNotIn('status', [PayableStatus::PAID->value, PayableStatus::CANCELLED->value])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum('balance_amount');

        $openReceivables = DB::table('account_receivable_installments')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->whereNotIn('status', [ReceivableStatus::RECEIVED->value, ReceivableStatus::CANCELLED->value])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum('balance_amount');

        return round((float) $movements + (((float) $openReceivables - (float) $openPayables) / 100), 2);
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $companyIds
     */
    private function manualCashMovementAmount(array $accountIds, array $companyIds, string $dateColumn, string $startDate, string $endDate, ?int $costCenterId, ?int $resultCenterId): float
    {
        return round((float) DB::table('cash_movements')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->where('origin_type', 'manual')
            ->whereNull('reversal_of_id')
            ->whereBetween($dateColumn, [$startDate, $endDate])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->get(['direction', 'amount'])
            ->sum(fn (object $movement): float => ((string) $movement->direction === CashMovementDirection::INFLOW->value ? 1 : -1) * ((float) $movement->amount / 100)), 2);
    }

    /**
     * @param  Collection<int, DreLine>  $lines
     * @param  Collection<int, float>  $amountsByLine
     * @return Collection<int, DreLineResultDTO>
     */
    private function buildLineTree(Collection $lines, Collection $amountsByLine, float $revenueBase, ?int $parentId = null, int $depth = 0): Collection
    {
        return $lines
            ->where('parent_id', $parentId)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(function (DreLine $line) use ($lines, $amountsByLine, $revenueBase, $depth): DreLineResultDTO {
                $children = $this->buildLineTree($lines, $amountsByLine, $revenueBase, (int) $line->id, $depth + 1);
                $ownAmount = (float) ($amountsByLine->get($line->id) ?? 0);
                $amount = $line->line_type === DreLineType::SUBTOTAL
                    ? round((float) $children->sum('amount'), 2)
                    : $ownAmount;

                return new DreLineResultDTO(
                    lineId: (int) $line->id,
                    name: $line->name,
                    code: $line->code,
                    lineType: $line->line_type?->value ?? '',
                    amount: $amount,
                    percentage: $revenueBase > 0 ? round(($amount / $revenueBase) * 100, 2) : null,
                    depth: $depth,
                    displayDepth: max(0, (int) ($line->display_depth ?? $depth)),
                    isBold: (bool) $line->is_bold,
                    isVisible: (bool) $line->is_visible,
                    children: $children,
                );
            })
            ->values();
    }
}
