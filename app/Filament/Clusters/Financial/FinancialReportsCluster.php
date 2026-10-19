<?php

namespace App\Filament\Clusters\Financial;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinancialReportsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Relatórios';

    protected static ?string $clusterBreadcrumb = 'Relatórios';

    protected static ?string $slug = 'relatorios-financeiros';

    protected static ?int $navigationSort = 20;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;
}
