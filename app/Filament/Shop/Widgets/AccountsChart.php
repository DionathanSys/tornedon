<?php

namespace App\Filament\Shop\Widgets;

use App\Enum\AccountReceivable\Status as ArStatus;
use App\Enum\AccountPayable\Status as ApStatus;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class AccountsChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Contas a Receber vs a Pagar (pendentes de todos os períodos)';
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant?->getKey();

        if (! $tenantId) {
            return ['datasets' => [], 'labels' => []];
        }

        $arTotal = AccountReceivable::query()
            ->where('company_id', $tenantId)
            ->whereIn('status', [
                ArStatus::PENDING->value,
                ArStatus::OVERDUE->value,
                ArStatus::PARTIALLY_RECEIVED->value,
            ])
            ->selectRaw('COALESCE(SUM(due_amount - COALESCE(paid_amount, 0)), 0) as total')
            ->value('total') / 100;

        $apTotal = AccountPayable::query()
            ->where('company_id', $tenantId)
            ->whereIn('status', [
                ApStatus::PENDING->value,
                ApStatus::OVERDUE->value,
                ApStatus::PARTIALLY_PAID->value,
            ])
            ->selectRaw('COALESCE(SUM(due_amount - COALESCE(paid_amount, 0)), 0) as total')
            ->value('total') / 100;

        return [
            'datasets' => [
                [
                    'data' => [max($arTotal, 0), max($apTotal, 0)],
                    'backgroundColor' => ['#10b981', '#ef4444'],
                    'borderColor' => ['#059669', '#dc2626'],
                    'borderWidth' => 1,
                ],
            ],
            'labels' => [
                'A Receber (R$ ' . number_format($arTotal, 2, ',', '.') . ')',
                'A Pagar (R$ ' . number_format($apTotal, 2, ',', '.') . ')',
            ],
        ];
    }
}
