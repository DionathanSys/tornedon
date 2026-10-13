<?php

namespace App\Filament\Operation\Pages;

use App\Filament\Operation\Concerns\AuthorizesOperationModule;
use App\Filament\Operation\Widgets\AccountsChart;
use App\Filament\Operation\Widgets\ProductionRequestChart;
use App\Filament\Operation\Widgets\RevenueChart;
use App\Filament\Operation\Widgets\StatsOverview;
use Filament\Pages\Dashboard;

class CommercialDashboard extends Dashboard
{
    use AuthorizesOperationModule;

    protected static string $layout = 'components.operation.layout';

    protected static string $routePath = '/producao-financeiro';

    protected static ?string $title = 'Produção e financeiro';

    protected string $view = 'filament.operation.pages.commercial-dashboard';

    public function getWidgets(): array
    {
        return [StatsOverview::class, ProductionRequestChart::class, RevenueChart::class, AccountsChart::class];
    }
}
