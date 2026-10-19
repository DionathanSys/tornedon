<?php

namespace App\Filament\Clusters\Financial;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CommitmentsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Empenhos';

    protected static ?string $clusterBreadcrumb = 'Empenhos';

    protected static ?string $slug = 'empenhos';

    protected static ?int $navigationSort = 8;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;
}
