<?php

namespace App\Filament\Clusters\Financial\Resources\CostCenters\Pages;

use App\Filament\Clusters\Financial\Resources\CostCenters\CostCenterResource;
use Filament\Resources\Pages\ListRecords;

class ListCostCenters extends ListRecords
{
    protected static string $resource = CostCenterResource::class;
}
