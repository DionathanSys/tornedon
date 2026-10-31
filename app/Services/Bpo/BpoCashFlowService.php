<?php

namespace App\Services\Bpo;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class BpoCashFlowService
{
    public function __construct(private BpoAccessService $access, private CompanyAuthorization $authorization) {}

    public function report(User $user, array $companyIds, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate)->startOfDay();
        abort_unless($end >= $start && $start->diffInDays($end) <= 365, 422);
        $companies = $this->access->companies($user);
        abort_unless(collect($companyIds)->every(fn ($id) => $companies->contains('id', $id)), 403);
        $selected = $companies->whereIn('id', $companyIds);
        $rows = $selected->map(fn ($company) => $this->companyReport($user, $company, $start, $end));
        $days = [];
        $balance = $rows->sum('opening');
        foreach ($start->daysUntil($end) as $day) {
            $date = $day->toDateString();
            $in = $rows->sum(fn ($row) => $row['days'][$date]['in']);
            $out = $rows->sum(fn ($row) => $row['days'][$date]['out']);
            $adjustment = $rows->sum(fn ($row) => $row['days'][$date]['adjustment']);
            $days[$date] = ['date' => $date, 'opening' => $balance, 'in' => $in, 'out' => $out,
                'adjustment' => $adjustment, 'balance' => $balance += $in - $out + $adjustment];
        }

        return ['companies' => $companies, 'rows' => $rows, 'days' => $days,
            'opening' => $rows->sum('opening'), 'closing' => $balance,
            'in' => array_sum(array_column($days, 'in')), 'out' => array_sum(array_column($days, 'out')),
            'overdue_receivables' => $rows->sum('overdue_receivables'),
            'partial' => $rows->contains('partial', true),
            'first_shortage' => collect($days)->first(fn ($day) => $day['balance'] < 0),
            'titles' => $rows->flatMap(fn ($row) => $row['titles'])->sortBy('date')->values(),
            'accounts' => $rows->flatMap(fn ($row) => $row['accounts'])->values()];
    }

    private function companyReport(User $user, Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $permissions = $this->authorization->effectivePermissions($user, $company);
        $partial = collect(['financial_accounts.view', 'cash_movements.view', 'account_payables.view', 'account_receivables.view'])
            ->contains(fn ($key) => ! in_array('company', $permissions[$key] ?? [], true));
        $accounts = $this->authorization->scopeQuery(FinancialAccount::query(), $user, $company, 'financial_accounts.view')->get();
        // Include reversal entries: they cancel the original movement on their own transaction date.
        $movementQuery = $this->authorization->scopeQuery(CashMovement::query(), $user, $company, 'cash_movements.view')
            ->whereIn('financial_account_id', $accounts->modelKeys())->whereDate('transaction_date', '<=', $end);
        $movements = (clone $movementQuery)
            ->selectRaw("financial_account_id, transaction_date, SUM(CASE WHEN direction = 'inflow' THEN amount ELSE 0 END) as inflow, SUM(CASE WHEN direction = 'outflow' THEN amount ELSE 0 END) as outflow")
            ->groupBy('financial_account_id', 'transaction_date')->get();
        $in = [];
        $out = [];
        $projectedBeforeStart = 0;
        foreach ($movements as $movement) {
            $date = $movement->transaction_date->toDateString();
            $in[$date] = ($in[$date] ?? 0) + (int) $movement->inflow;
            $out[$date] = ($out[$date] ?? 0) + (int) $movement->outflow;
        }
        // Transfers between visible accounts do not create income, expenses or a need for receipts.
        $transfers = (clone $movementQuery)->where('origin_type', 'manual')->whereNotNull('transfer_group_id')
            ->whereDate('transaction_date', '>=', $start)
            ->selectRaw("transfer_group_id, transaction_date, COUNT(DISTINCT financial_account_id) as accounts_count, SUM(CASE WHEN direction = 'inflow' THEN amount ELSE 0 END) as inflow, SUM(CASE WHEN direction = 'outflow' THEN amount ELSE 0 END) as outflow")
            ->groupBy('transfer_group_id', 'transaction_date')->get();
        foreach ($transfers as $transfer) {
            if ((int) $transfer->accounts_count !== 2 || (int) $transfer->inflow !== (int) $transfer->outflow) {
                continue;
            }
            $date = $transfer->transaction_date->toDateString();
            $in[$date] -= (int) $transfer->inflow;
            $out[$date] -= (int) $transfer->outflow;
        }
        $titles = collect();
        $overdueReceivables = 0;
        foreach (['payable', 'receivable'] as $type) {
            $payable = $type === 'payable';
            $relation = $payable ? 'accountPayable' : 'accountReceivable';
            $permission = $payable ? 'account_payables.view' : 'account_receivables.view';
            $model = $payable ? AccountPayableInstallment::class : AccountReceivableInstallment::class;
            $installments = $model::query()->where('company_id', $company->id)->open()
                ->whereHas($relation, fn (Builder $query) => $this->authorization->scopeQuery($query, $user, $company, $permission))
                ->whereDate('due_date', '<=', $end->max(CarbonImmutable::today()))->with($relation.($payable ? '.supplier' : '.customer'))->get();
            foreach ($installments as $installment) {
                $amount = (int) $installment->getRawOriginal('balance_amount');
                $overdue = $installment->due_date->lt(today());
                if (! $payable && $overdue) {
                    $overdueReceivables += $amount;
                }
                $date = $overdue && $payable ? CarbonImmutable::today() : CarbonImmutable::instance($installment->due_date);
                if ($date->gte(CarbonImmutable::today()) && $date->lte($end) && ! (! $payable && $overdue)) {
                    if ($date->lt($start)) {
                        $projectedBeforeStart += $payable ? -$amount : $amount;
                    }
                    if ($payable) {
                        $out[$date->toDateString()] = ($out[$date->toDateString()] ?? 0) + $amount;
                    } else {
                        $in[$date->toDateString()] = ($in[$date->toDateString()] ?? 0) + $amount;
                    }
                }
                if (($date->betweenIncluded($start, $end) && $date->gte(CarbonImmutable::today())) || (! $payable && $overdue)) {
                    $parent = $installment->$relation;
                    $titles->push(['type' => $type, 'company' => $company, 'record' => $parent,
                        'description' => $installment->description ?: $parent->description,
                        'party' => $parent->counterparty_label, 'date' => $installment->due_date->toDateString(),
                        'amount' => $amount, 'overdue' => $overdue]);
                }
            }
        }
        $opening = 0;
        $adjustments = [];
        $accountRows = $accounts->map(function ($account) use ($movements, $start, &$opening, &$adjustments, $company) {
            $initial = (int) $account->getRawOriginal('opening_balance');
            $opened = $account->opened_at?->toDateString();
            if (! $opened || $opened < $start->toDateString()) {
                $opening += $initial;
            } else {
                $adjustments[$opened] = ($adjustments[$opened] ?? 0) + $initial;
            }
            $past = $movements->where('financial_account_id', $account->id)->filter(fn ($m) => $m->transaction_date->lt($start));
            $net = (int) $past->sum(fn ($m) => (int) $m->inflow - (int) $m->outflow);
            $opening += $net;

            return ['company' => $company, 'name' => $account->display_name,
                'balance' => ((! $opened || $opened < $start->toDateString()) ? $initial : 0) + $net];
        });
        // A future period starts with today's balance carried through intervening open commitments.
        $opening += $projectedBeforeStart;
        $balance = $opening;
        $available = $opening;
        $days = [];
        foreach ($start->daysUntil($end) as $day) {
            $date = $day->toDateString();
            $incoming = $in[$date] ?? 0;
            $outgoing = $out[$date] ?? 0;
            $adjustment = $adjustments[$date] ?? 0;
            $available += $adjustment - $outgoing;
            $days[$date] = ['date' => $date, 'in' => $incoming, 'out' => $outgoing, 'adjustment' => $adjustment,
                'balance' => $balance += $incoming - $outgoing + $adjustment];
        }

        return ['company' => $company, 'partial' => $partial, 'opening' => $opening, 'closing' => $balance,
            'in' => array_sum(array_column($days, 'in')), 'out' => array_sum(array_column($days, 'out')),
            'without_receipts' => $available, 'days' => $days, 'accounts' => $accountRows, 'titles' => $titles,
            'overdue_receivables' => $overdueReceivables,
            'first_shortage' => collect($days)->first(fn ($day) => $day['balance'] < 0)];
    }
}
