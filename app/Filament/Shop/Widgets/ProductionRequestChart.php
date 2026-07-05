<?php

namespace App\Filament\Shop\Widgets;

use App\Enum\ProductionRequest\Status;
use App\Models\ProductionRequest;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class ProductionRequestChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected function getType(): string
    {
        return 'line';
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Pedidos para Produção (últimos 30 dias)';
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant?->getKey();

        if (! $tenantId) {
            return ['datasets' => [], 'labels' => []];
        }

        $records = ProductionRequest::query()
            ->where('company_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as date, status, COUNT(*) as count')
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get()
            ->groupBy('date');

        $dates = collect();
        $start = now()->subDays(29)->startOfDay();

        foreach (range(0, 29) as $i) {
            $dates->push($start->copy()->addDays($i)->format('Y-m-d'));
        }

        $open = [];
        $delivered = [];
        $cancelled = [];

        foreach ($dates as $date) {
            $day = $records->get($date, collect());
            $open[] = $day->firstWhere('status', Status::OPEN->value)?->count ?? 0;
            $delivered[] = $day->firstWhere('status', Status::DELIVERED->value)?->count ?? 0;
            $cancelled[] = $day->firstWhere('status', Status::CANCELLED->value)?->count ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Abertos',
                    'data' => $open,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => '#f59e0b',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Entregues',
                    'data' => $delivered,
                    'borderColor' => '#10b981',
                    'backgroundColor' => '#10b981',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Cancelados',
                    'data' => $cancelled,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => '#ef4444',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $dates->map(fn (string $d): string => \Carbon\Carbon::parse($d)->format('d/m'))->toArray(),
        ];
    }
}
