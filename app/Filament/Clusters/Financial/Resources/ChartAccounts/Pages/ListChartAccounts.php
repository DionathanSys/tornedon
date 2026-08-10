<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages;

use App\Filament\Clusters\Financial\Resources\ChartAccounts\ChartAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListChartAccounts extends ListRecords
{
    protected static string $resource = ChartAccountResource::class;
}
