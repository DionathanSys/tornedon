<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListProductionRequests extends ListRecords
{
    protected static string $resource = ProductionRequestResource::class;
}
