<?php

namespace App\Filament\Clusters\Financial;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinancialRegistrationsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Cadastros Financeiros';

    protected static ?string $clusterBreadcrumb = 'Cadastros Financeiros';

    protected static ?string $slug = 'cadastros-financeiros';

    protected static ?int $navigationSort = 14;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;
}
