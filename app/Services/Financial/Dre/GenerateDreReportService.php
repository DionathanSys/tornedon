<?php

namespace App\Services\Financial\Dre;

use App\DTO\Financial\DreLineResultDTO;
use App\DTO\Financial\DreReportDTO;
use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Enum\Financial\DreDisplaySign;
use App\Enum\Financial\DreLineType;
use App\Enum\Financial\DreMode;
use App\Enum\Financial\DreOperation;
use App\Enum\Financial\DreView;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\DreLine;
use App\Models\DreModel;
use App\Support\Financial\GroupedFinancialEffect;
use App\Support\Financial\Money;
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
        $revenueBase = abs((int) $amountsByLine->filter(fn (int $amount): bool => $amount > 0)->sum());
        $tree = $this->buildLineTree($lines, $amountsByLine, $revenueBase);

        return new DreReportDTO(
            dreModelId: (int) $dreModel->id,
            companyIds: $companyIds,
            startDate: $start,
            endDate: $end,
            mode: $mode->value,
            view: $view->value,
            revenueBase: (float) Money::decimal($revenueBase),
            lines: $tree,
        );
    }

    /**
     * @param  Collection<int, DreLine>  $lines
     * @param  array<int, int>  $companyIds
     * @return Collection<int, int> Amounts in cents.
     */
    private function amountsByLine(Collection $lines, array $companyIds, string $startDate, string $endDate, DreMode $mode, DreView $view, ?int $costCenterId, ?int $resultCenterId): Collection
    {
        $discountLines = $lines->where('line_type', DreLineType::RECEIPT_DISCOUNTS);
        if ($discountLines->count() > 1) {
            throw ValidationException::withMessages(['dre_model_id' => 'Use apenas uma linha automática de descontos por modelo.']);
        }
        $separateDiscounts = $discountLines->isNotEmpty();
        if ($separateDiscounts) {
            $seen = [];
            foreach ($lines->where('line_type', DreLineType::ACCOUNT_GROUP) as $group) {
                foreach ($this->lineChartAccountIds($group) as $id) {
                    if (isset($seen[$id])) {
                        throw ValidationException::withMessages(['dre_model_id' => 'Uma conta está vinculada a mais de um grupo. Revise as linhas para evitar valores duplicados.']);
                    }
                    $seen[$id] = true;
                }
            }
        }
        $mappedIds = $lines->where('line_type', DreLineType::ACCOUNT_GROUP)
            ->flatMap(fn (DreLine $line): array => $this->lineChartAccountIds($line))->unique()->all();

        return $lines->mapWithKeys(function (DreLine $line) use ($companyIds, $startDate, $endDate, $mode, $view, $costCenterId, $resultCenterId, $separateDiscounts, $mappedIds): array {
            if ($line->line_type === DreLineType::RECEIPT_DISCOUNTS) {
                return [$line->id => -$this->receiptDiscountAmount($mappedIds, $companyIds, $startDate, $endDate, $mode, $view, $costCenterId, $resultCenterId)];
            }
            if ($line->line_type !== DreLineType::ACCOUNT_GROUP) {
                return [$line->id => 0];
            }

            $accountIds = $this->lineChartAccountIds($line);
            if ($accountIds === []) {
                return [$line->id => 0];
            }

            $amount = $mode === DreMode::CASH
                ? $this->cashAmount($accountIds, $companyIds, $startDate, $endDate, $view, $costCenterId, $resultCenterId)
                : $this->competenceAmount($accountIds, $companyIds, $startDate, $endDate, $view, $costCenterId, $resultCenterId);

            // Recompose the gross revenue only when the model displays the matching expense.
            if ($separateDiscounts) {
                $amount += $this->receiptDiscountAmount($accountIds, $companyIds, $startDate, $endDate, $mode, $view, $costCenterId, $resultCenterId);
            }

            $operation = $line->operation instanceof DreOperation ? $line->operation : DreOperation::ADD;

            return [$line->id => $amount * $operation->multiplier()];
        });
    }

    private function receiptDiscountAmount(array $accountIds, array $companyIds, string $startDate, string $endDate, DreMode $mode, DreView $view, ?int $costCenterId, ?int $resultCenterId): int
    {
        if ($view === DreView::PROJECTED_ONLY || $accountIds === []) {
            return 0;
        }

        $query = DB::table('account_receivable_installment_payments as p')
            ->join('account_receivable_installments as i', 'i.id', '=', 'p.account_receivable_installment_id')
            ->whereIn('i.company_id', $companyIds)
            ->whereColumn('p.company_id', 'i.company_id');
        GroupedFinancialEffect::effective($query, 'p.id', 'receivable_payment_id');

        if ($mode === DreMode::COMPETENCE) {
            // A returned payment no longer grants the discount. Follow the installment's competence.
            $amount = $query->whereIn('i.chart_account_id', $accountIds)
                ->where('i.status', '!=', ReceivableStatus::CANCELLED->value)
                ->when($costCenterId, fn ($q) => $q->where('i.cost_center_id', $costCenterId))
                ->when($resultCenterId, fn ($q) => $q->where('i.result_center_id', $resultCenterId))
                ->whereBetween(DB::raw('COALESCE(i.competence_date, i.due_date)'), [$startDate, $endDate])
                ->where('p.returned_amount', 0)
                ->sum('p.discount_amount');
        } else {
            // Use actual movements, including reversals in THEIR period, without creating cash entries.
            $amount = $query->join('cash_movements as original', function ($join): void {
                $join->on('original.origin_id', '=', 'p.id')
                    ->on('original.company_id', '=', 'p.company_id')
                    ->where('original.origin_type', AccountReceivableInstallmentPayment::class)
                    ->whereNull('original.reversal_of_id');
            })->join('cash_movements as m', function ($join): void {
                $join->on('m.company_id', '=', 'original.company_id')
                    ->where(function ($q): void {
                        $q->whereColumn('m.id', 'original.id')->orWhereColumn('m.reversal_of_id', 'original.id');
                    });
            })->whereIn('m.chart_account_id', $accountIds)
                ->when($costCenterId, fn ($q) => $q->where('m.cost_center_id', $costCenterId))
                ->when($resultCenterId, fn ($q) => $q->where('m.result_center_id', $resultCenterId))
                ->whereBetween('m.transaction_date', [$startDate, $endDate])
                ->get(['p.discount_amount', 'p.amount as payment_amount', 'm.amount as movement_amount', 'm.direction'])
                ->sum(fn ($row) => Money::proportion((int) $row->discount_amount, (int) $row->movement_amount, (int) $row->payment_amount) * ($row->direction === 'inflow' ? 1 : -1));
        }

        return (int) $amount;
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
    private function competenceAmount(array $accountIds, array $companyIds, string $startDate, string $endDate, DreView $view, ?int $costCenterId, ?int $resultCenterId): int
    {
        $payables = DB::table('account_payable_installments')
            ->when($view === DreView::PROJECTED_ONLY, fn ($query) => $query->whereNull('ignored_at'))
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween(DB::raw('COALESCE(competence_date, due_date)'), [$startDate, $endDate])
            ->where('status', '!=', PayableStatus::CANCELLED->value)
            ->when($view === DreView::REALIZED, fn ($query) => $query->whereIn('status', [PayableStatus::PAID->value, PayableStatus::PARTIALLY_PAID->value]))
            ->when($view === DreView::PROJECTED_ONLY, fn ($query) => $query->whereNotIn('status', [PayableStatus::PAID->value, PayableStatus::CANCELLED->value]))
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum(match ($view) {
                DreView::REALIZED => 'paid_amount',
                DreView::PROJECTED_ONLY => 'balance_amount',
                default => DB::raw('CASE WHEN ignored_at IS NULL THEN due_amount ELSE paid_amount END'),
            });

        $receivables = DB::table('account_receivable_installments')
            ->when($view === DreView::PROJECTED_ONLY, fn ($query) => $query->whereNull('ignored_at'))
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween(DB::raw('COALESCE(competence_date, due_date)'), [$startDate, $endDate])
            ->where('status', '!=', ReceivableStatus::CANCELLED->value)
            ->when($view === DreView::REALIZED, fn ($query) => $query->whereIn('status', [ReceivableStatus::RECEIVED->value, ReceivableStatus::PARTIALLY_RECEIVED->value]))
            ->when($view === DreView::PROJECTED_ONLY, fn ($query) => $query->whereNotIn('status', [ReceivableStatus::RECEIVED->value, ReceivableStatus::CANCELLED->value]))
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum(match ($view) {
                DreView::REALIZED => 'received_amount',
                DreView::PROJECTED_ONLY => 'balance_amount',
                default => DB::raw('CASE WHEN ignored_at IS NULL THEN due_amount ELSE received_amount END'),
            });

        $manualMovements = $view === DreView::PROJECTED_ONLY
            ? 0
            : $this->manualCashMovementAmount($accountIds, $companyIds, 'COALESCE(competence_date, transaction_date)', $startDate, $endDate, $costCenterId, $resultCenterId);

        return (int) $receivables - (int) $payables + $manualMovements;
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $companyIds
     */
    private function cashAmount(array $accountIds, array $companyIds, string $startDate, string $endDate, DreView $view, ?int $costCenterId, ?int $resultCenterId): int
    {
        $movements = DB::table('cash_movements')
            ->tap(fn ($query) => GroupedFinancialEffect::effective($query, 'cash_movements.id', 'cash_movement_id'))
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->where(function ($query): void {
                $query->where('origin_type', 'manual')
                    ->orWhereIn('reversal_of_id', DB::table('cash_movements')->select('id')->whereIn('origin_type', [
                        'manual', AccountPayableInstallmentPayment::class, AccountReceivableInstallmentPayment::class,
                    ]))
                    ->orWhere('origin_type', AccountPayableInstallmentPayment::class)
                    ->orWhere('origin_type', AccountReceivableInstallmentPayment::class);
            })
            ->sum(DB::raw("CASE WHEN direction = 'inflow' THEN amount ELSE -amount END"));

        if ($view === DreView::REALIZED) {
            return (int) $movements;
        }

        $openPayables = DB::table('account_payable_installments')
            ->whereNull('ignored_at')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->whereNotIn('status', [PayableStatus::PAID->value, PayableStatus::CANCELLED->value])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum('balance_amount');

        $openReceivables = DB::table('account_receivable_installments')
            ->whereNull('ignored_at')
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->whereNotIn('status', [ReceivableStatus::RECEIVED->value, ReceivableStatus::CANCELLED->value])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum('balance_amount');

        return ($view === DreView::PROJECTED_ONLY ? 0 : (int) $movements) + (int) $openReceivables - (int) $openPayables;
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $companyIds
     */
    private function manualCashMovementAmount(array $accountIds, array $companyIds, string $dateColumn, string $startDate, string $endDate, ?int $costCenterId, ?int $resultCenterId): int
    {
        return (int) DB::table('cash_movements')
            ->tap(fn ($query) => GroupedFinancialEffect::effective($query, 'cash_movements.id', 'cash_movement_id'))
            ->whereIn('company_id', $companyIds)
            ->whereIn('chart_account_id', $accountIds)
            ->where(function ($query): void {
                $query->where('origin_type', 'manual')
                    ->orWhereIn('reversal_of_id', DB::table('cash_movements')->select('id')->where('origin_type', 'manual'));
            })
            ->whereBetween(DB::raw($dateColumn), [$startDate, $endDate])
            ->when($costCenterId, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($resultCenterId, fn ($query) => $query->where('result_center_id', $resultCenterId))
            ->sum(DB::raw("CASE WHEN direction = 'inflow' THEN amount ELSE -amount END"));
    }

    /**
     * @param  Collection<int, DreLine>  $lines
     * @param  Collection<int, int>  $amountsByLine
     * @return Collection<int, DreLineResultDTO>
     */
    private function buildLineTree(Collection $lines, Collection $amountsByLine, int $revenueBase, ?int $parentId = null, int $depth = 0): Collection
    {
        $runningAmount = 0;

        return $lines
            ->where('parent_id', $parentId)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(function (DreLine $line) use ($lines, $amountsByLine, $revenueBase, $depth, &$runningAmount): DreLineResultDTO {
                $children = $this->buildLineTree($lines, $amountsByLine, $revenueBase, (int) $line->id, $depth + 1);
                $ownAmount = (int) ($amountsByLine->get($line->id) ?? 0);
                $amount = in_array($line->line_type, [DreLineType::SUBTOTAL, DreLineType::HEADER], true)
                    ? (int) $children->reject(fn ($child) => $child->lineType === DreLineType::RUNNING_TOTAL->value)->sum(fn ($child) => Money::cents($child->amount))
                    : $ownAmount;
                if ($line->line_type === DreLineType::RUNNING_TOTAL) {
                    $amount = $runningAmount;
                } else {
                    $runningAmount += $amount;
                }

                return new DreLineResultDTO(
                    lineId: (int) $line->id,
                    name: $line->name,
                    code: $line->code,
                    lineType: $line->line_type?->value ?? '',
                    amount: (float) Money::decimal($amount),
                    percentage: $revenueBase > 0 ? round(($amount / $revenueBase) * 100, 2) : null,
                    depth: $depth,
                    displayDepth: max(0, (int) ($line->display_depth ?? $depth)),
                    isBold: (bool) $line->is_bold,
                    isVisible: (bool) $line->is_visible,
                    displaySign: $line->display_sign?->value ?? DreDisplaySign::NATURAL->value,
                    children: $children,
                );
            })
            ->values();
    }
}
