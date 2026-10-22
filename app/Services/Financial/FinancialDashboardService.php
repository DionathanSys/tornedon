<?php

namespace App\Services\Financial;

use App\Enum\AccountPayable\Status as PayableStatus;
use App\Enum\AccountReceivable\Status as ReceivableStatus;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class FinancialDashboardService
{
    public const PERIODS = [
        'today' => 'Hoje',
        'tomorrow' => 'Amanhã',
        'next_7_days' => 'Próximos 7 dias',
        'next_30_days' => 'Próximos 30 dias',
        'overdue' => 'Vencidos',
    ];

    public function query(int $companyId, string $type, string $period): Builder
    {
        if (! array_key_exists($period, self::PERIODS)) {
            throw new InvalidArgumentException('Período inválido.');
        }

        [$model, $relation, $statuses] = match ($type) {
            'receivable' => [AccountReceivableInstallment::class, 'accountReceivable', [ReceivableStatus::PENDING->value, ReceivableStatus::PARTIALLY_RECEIVED->value, ReceivableStatus::OVERDUE->value]],
            'payable' => [AccountPayableInstallment::class, 'accountPayable', [PayableStatus::PENDING->value, PayableStatus::PARTIALLY_PAID->value, PayableStatus::OVERDUE->value]],
            default => throw new InvalidArgumentException('Tipo de conta inválido.'),
        };

        $query = $model::query()
            ->where('company_id', $companyId)
            ->whereIn('status', $statuses)
            ->where('balance_amount', '>', 0)
            ->whereHas($relation, fn (Builder $account) => $account
                ->where('company_id', $companyId)
                ->whereIn('status', $statuses));

        $today = today();

        return match ($period) {
            'overdue' => $query->whereDate('due_date', '<', $today->toDateString()),
            'today' => $query->whereDate('due_date', $today->toDateString()),
            'tomorrow' => $query->whereDate('due_date', $today->addDay()->toDateString()),
            'next_7_days', 'next_30_days' => $query
                ->whereDate('due_date', '>=', $today->toDateString())
                ->whereDate('due_date', '<=', $today->copy()->addDays($period === 'next_7_days' ? 6 : 29)->toDateString()),
        };
    }

    public function total(int $companyId, string $type, string $period): float
    {
        // SUM works on database cents, without applying MoneyCast.
        return (float) $this->query($companyId, $type, $period)->sum('balance_amount') / 100;
    }
}
