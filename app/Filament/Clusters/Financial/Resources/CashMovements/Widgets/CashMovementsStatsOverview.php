<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Widgets;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ListCashMovements;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashMovementsStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected function getTablePage(): string
    {
        return ListCashMovements::class;
    }

    protected function getStats(): array
    {
        $baseQuery = $this->getPageTableQuery()->clone()->reorder();

        $inflow = $this->resolveAmountByDirection($baseQuery->clone(), CashMovementDirection::INFLOW);
        $outflow = $this->resolveAmountByDirection($baseQuery->clone(), CashMovementDirection::OUTFLOW);
        $count = $baseQuery->clone()->count();
        $net = round($inflow - $outflow, 2);

        return [
            Stat::make('Entradas', $this->formatMoney($inflow))
                ->description('Total de entradas no período')
                ->color('success'),
            Stat::make('Saídas', $this->formatMoney($outflow))
                ->description('Total de saídas no período')
                ->color('danger'),
            Stat::make('Saldo líquido', $this->formatMoney($net))
                ->description('Entradas menos saídas')
                ->color($net >= 0 ? 'success' : 'danger'),
            Stat::make('Movimentos', number_format($count, 0, ',', '.'))
                ->description('Quantidade de lançamentos filtrados')
                ->color('gray'),
        ];
    }

    private function resolveAmountByDirection($query, CashMovementDirection $direction): float
    {
        $amount = (float) ($query
            ->where('direction', $direction->value)
            ->sum('amount') ?? 0);

        return round(abs($amount) / 100, 2);
    }

    private function formatMoney(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, ',', '.');
    }
}
