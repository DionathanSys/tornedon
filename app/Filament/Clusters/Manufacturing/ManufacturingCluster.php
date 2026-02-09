<?php

namespace App\Filament\Clusters\Manufacturing;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class ManufacturingCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;

    protected static ?string $navigationLabel = 'Produção';

    protected static ?int $navigationSort = 4;
}
