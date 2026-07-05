<?php

namespace App\Filament\Shop\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class ShopDashboard extends Dashboard
{
    protected static string $routePath = '/';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Home;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -2;

    public function getTitle(): string | Htmlable
    {
        return 'Dashboard';
    }

    public function getColumns(): int | array
    {
        return 3;
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            \App\Filament\Shop\Widgets\StatsOverview::class,
            \App\Filament\Shop\Widgets\ProductionRequestChart::class,
            \App\Filament\Shop\Widgets\RevenueChart::class,
            \App\Filament\Shop\Widgets\AccountsChart::class,
        ];
    }
}
