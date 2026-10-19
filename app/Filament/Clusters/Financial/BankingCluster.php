<?php

namespace App\Filament\Clusters\Financial;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class BankingCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Bancos e Conciliação';

    protected static ?string $clusterBreadcrumb = 'Bancos e Conciliação';

    protected static ?string $slug = 'bancos-e-conciliacao';

    protected static ?int $navigationSort = 7;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;
}
