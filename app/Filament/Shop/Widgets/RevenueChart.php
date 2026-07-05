<?php

namespace App\Filament\Shop\Widgets;

use App\Models\AccountReceivable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class RevenueChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Receita (últimos 6 meses)';
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant?->getKey();

        if (! $tenantId) {
            return ['datasets' => [], 'labels' => []];
        }

        $months = collect();
        $start = now()->subMonths(5)->startOfMonth();

        foreach (range(0, 5) as $i) {
            $months->push($start->copy()->addMonths($i));
        }

        $labels = $months->map(fn ($d) => $d->format('M/Y'));

        $driver = DB::connection()->getDriverName();
        $dateFormat = $driver === 'sqlite'
            ? "strftime('%Y-%m', paid_date)"
            : "DATE_FORMAT(paid_date, '%Y-%m')";

        $revenue = AccountReceivable::query()
            ->where('company_id', $tenantId)
            ->where('status', 'received')
            ->whereNotNull('paid_date')
            ->where('paid_date', '>=', $start)
            ->selectRaw("{$dateFormat} as month, COALESCE(SUM(paid_amount), 0) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $data = $months->map(fn ($d) => round(
            ($revenue->get($d->format('Y-m'), 0) ?? 0) / 100,
            2
        ))->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Recebido',
                    'data' => $data,
                    'backgroundColor' => '#10b981',
                    'borderColor' => '#059669',
                    'borderWidth' => 1,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }
}
