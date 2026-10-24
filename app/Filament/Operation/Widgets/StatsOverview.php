<?php

namespace App\Filament\Operation\Widgets;

use App\Enum\Financial\CashMovementDirection;
use App\Enum\ProductionRequest\Status as ProductionRequestStatus;
use App\Filament\Operation\Concerns\AuthorizesCommercialWidget;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\CashMovement;
use App\Models\ProductionRequest;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseStatsOverviewWidget
{
    use AuthorizesCommercialWidget;

    protected string $view = 'filament.operation.widgets.stats-overview';

    protected static bool $isLazy = false;

    public function getSummaryStats(): array
    {
        return $this->getCachedStats();
    }

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

        $arPending = AccountReceivableInstallment::query()->open()
            ->where('company_id', $tenantId)
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as total')
            ->value('total');

        $apPending = AccountPayableInstallment::query()->open()
            ->where('company_id', $tenantId)
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as total')
            ->value('total');

        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();
        $previousMonthStart = now()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = now()->subMonthNoOverflow()->endOfMonth();

        $cashIn = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::INFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereBetween('transaction_date', [$currentMonthStart, $currentMonthEnd])
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $cashOut = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::OUTFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereBetween('transaction_date', [$currentMonthStart, $currentMonthEnd])
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $totalCashIn = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::INFLOW->value)
            ->whereNull('reversal_of_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $totalCashOut = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::OUTFLOW->value)
            ->whereNull('reversal_of_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $generalBalance = (float) $totalCashIn - (float) $totalCashOut;

        $previousCashIn = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::INFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereBetween('transaction_date', [$previousMonthStart, $previousMonthEnd])
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        $previousCashOut = CashMovement::query()
            ->where('company_id', $tenantId)
            ->where('direction', CashMovementDirection::OUTFLOW->value)
            ->whereNull('reversal_of_id')
            ->whereBetween('transaction_date', [$previousMonthStart, $previousMonthEnd])
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->value('total');

        return [
            Stat::make('Pedidos em Aberto', $pending)
                ->description('Aguardando entrega')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->chart([7, 5, 8, 6, $pending]),

            Stat::make('Receita Total (Entregues)', 'R$ '.number_format($revenue, 2, ',', '.'))
                ->description('Valor total de pedidos entregues')
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('success')
                ->chart([3, 5, 4, 6, $revenue / 100]),

            Stat::make('A Receber', 'R$ '.number_format($arPending / 100, 2, ',', '.'))
                ->description('Saldo pendente')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('info'),

            Stat::make('A Pagar', 'R$ '.number_format($apPending / 100, 2, ',', '.'))
                ->description('Saldo pendente')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger'),

            Stat::make('Saldo Geral', 'R$ '.number_format($generalBalance / 100, 2, ',', '.'))
                ->description('Todas entradas - todas saídas')
                ->descriptionIcon('heroicon-o-scale')
                ->color($generalBalance >= 0 ? 'success' : 'danger'),

            Stat::make('Entradas do Mês', 'R$ '.number_format($cashIn / 100, 2, ',', '.'))
                ->description('Mês anterior: R$ '.number_format($previousCashIn / 100, 2, ',', '.'))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Saídas do Mês', 'R$ '.number_format($cashOut / 100, 2, ',', '.'))
                ->description('Mês anterior: R$ '.number_format($previousCashOut / 100, 2, ',', '.'))
                ->descriptionIcon('heroicon-o-credit-card')
                ->color('danger'),
        ];
    }
}
