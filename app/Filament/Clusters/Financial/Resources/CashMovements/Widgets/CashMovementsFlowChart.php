<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Widgets;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ListCashMovements;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageTable;

class CashMovementsFlowChart extends ChartWidget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Fluxo diário';

    protected ?string $description = 'Entradas, saídas e saldo líquido por data';

    protected function getTablePage(): string
    {
        return ListCashMovements::class;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = $this->getPageTableQuery()
            ->clone()
            ->reorder()
            ->selectRaw('transaction_date')
            ->selectRaw("SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as inflow_total", [CashMovementDirection::INFLOW->value])
            ->selectRaw("SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) as outflow_total", [CashMovementDirection::OUTFLOW->value])
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get();

        $labels = [];
        $inflowData = [];
        $outflowData = [];
        $netData = [];

        foreach ($rows as $row) {
            $inflow = round(((float) $row->inflow_total) / 100, 2);
            $outflow = round(abs((float) $row->outflow_total) / 100, 2);
            $net = round($inflow - $outflow, 2);

            $labels[] = $row->transaction_date?->format('d/m/Y') ?? (string) $row->transaction_date;
            $inflowData[] = $inflow;
            $outflowData[] = $outflow;
            $netData[] = $net;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Entradas',
                    'data' => $inflowData,
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.12)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Saídas',
                    'data' => $outflowData,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.12)',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Saldo líquido',
                    'data' => $netData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.12)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}
