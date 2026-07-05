<?php

namespace App\Filament\Shop\Widgets;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\ProductionRequest\Status as ProductionRequestStatus;
use App\Enum\Financial\CashMovementDirection;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\CashMovement;
use App\Models\ProductionRequest;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseStatsOverviewWidget
{
    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant?->getKey();

        if (! $tenantId) {
            return [];
        }

        $pending = ProductionRequest::query()
            ->where('company_id', $tenantId)
            ->where('status', ProductionRequestStatus::OPEN->value)
            ->count();

        $revenue = ProductionRequest::query()
            ->where('company_id', $tenantId)
            ->where('status', ProductionRequestStatus::DELIVERED->value)
            ->with('items')
            ->get()
            ->sum(fn (ProductionRequest $pr): float => (float) $pr->total_amount);

        $arPending = AccountReceivable::query()
            ->where('company_id', $tenantId)
            ->whereIn('status', [
                AccountReceivableStatus::PENDING->value,
                AccountReceivableStatus::OVERDUE->value,
                AccountReceivableStatus::PARTIALLY_RECEIVED->value,
            ])
            ->selectRaw('COALESCE(SUM(due_amount - COALESCE(paid_amount, 0)), 0) as total')
            ->value('total');

        $apPending = AccountPayable::query()
            ->where('company_id', $tenantId)
            ->whereIn('status', [
                AccountPayableStatus::PENDING->value,
                AccountPayableStatus::OVERDUE->value,
                AccountPayableStatus::PARTIALLY_PAID->value,
            ])
            ->selectRaw('COALESCE(SUM(due_amount - COALESCE(paid_amount, 0)), 0) as total')
            ->value('total');

        $cashIn = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::INFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $cashOut = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::OUTFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        return [
            Stat::make('Pedidos em Aberto', $pending)
                ->description('Aguardando entrega')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->chart([7, 5, 8, 6, $pending]),

            Stat::make('Receita Total (Entregues)', 'R$ ' . number_format($revenue, 2, ',', '.'))
                ->description('Valor total de pedidos entregues')
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('success')
                ->chart([3, 5, 4, 6, $revenue / 100]),

            Stat::make('A Receber', 'R$ ' . number_format($arPending / 100, 2, ',', '.'))
                ->description('Saldo pendente')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('info'),

            Stat::make('A Pagar', 'R$ ' . number_format($apPending / 100, 2, ',', '.'))
                ->description('Saldo pendente')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger'),

            Stat::make('Entradas do Mês', 'R$ ' . number_format($cashIn / 100, 2, ',', '.'))
                ->description('Fluxo de caixa positivo')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Saídas do Mês', 'R$ ' . number_format($cashOut / 100, 2, ',', '.'))
                ->description('Fluxo de caixa negativo')
                ->descriptionIcon('heroicon-o-credit-card')
                ->color('danger'),
        ];
    }
}
