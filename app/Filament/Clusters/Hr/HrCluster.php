<?php

namespace App\Filament\Clusters\Hr;

use Filament\Clusters\Cluster;

class HrCluster extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'RH';

    protected static ?string $clusterBreadcrumb = 'Recursos Humanos';

    protected static ?int $navigationSort = 5;
}
