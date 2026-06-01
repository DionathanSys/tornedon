<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Widgets;

use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ListCashMovements;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CashMovementsReconciliationStats extends StatsOverviewWidget
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

        $total = $baseQuery->clone()->count();
        $reconciled = $baseQuery->clone()->whereHas('statementLines')->count();
        $pending = $baseQuery->clone()->whereDoesntHave('statementLines')->count();
        $rate = $total > 0 ? ($reconciled / $total) * 100 : 0;

        return [
            Stat::make('Conciliados', number_format($reconciled, 0, ',', '.'))
                ->description('Movimentos com linha bancária vinculada')
                ->color('success'),
            Stat::make('Não conciliados', number_format($pending, 0, ',', '.'))
                ->description('Movimentos ainda sem conciliação')
                ->color('warning'),
            Stat::make('Taxa de conciliação', $this->formatPercentage($rate))
                ->description('Percentual conciliado no recorte atual')
                ->color($rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger')),
        ];
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 1, ',', '.') . '%';
    }
}
