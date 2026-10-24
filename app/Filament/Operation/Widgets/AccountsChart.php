<?php

namespace App\Filament\Operation\Widgets;

use App\Filament\Operation\Concerns\AuthorizesCommercialWidget;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class AccountsChart extends ChartWidget
{
    use AuthorizesCommercialWidget;

    protected static ?int $sort = 4;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string|Htmlable|null
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

        $arTotal = AccountReceivableInstallment::query()->open()
            ->where('company_id', $tenantId)
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as total')
            ->value('total') / 100;

        $apTotal = AccountPayableInstallment::query()->open()
            ->where('company_id', $tenantId)
            ->selectRaw('COALESCE(SUM(balance_amount), 0) as total')
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
                'A Receber (R$ '.number_format($arTotal, 2, ',', '.').')',
                'A Pagar (R$ '.number_format($apTotal, 2, ',', '.').')',
            ],
        ];
    }
}
