<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages;

use App\Filament\Clusters\Sales\Resources\ProductionRequests\ProductionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListProductionRequests extends ListRecords
{
    protected static string $resource = ProductionRequestResource::class;
}
