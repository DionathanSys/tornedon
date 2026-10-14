<?php

namespace App\Filament\Operation\Resources\ProductionRequests\Pages;

use App\Filament\Operation\ModuleListPage;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;

class ListProductionRequests extends ModuleListPage
{
    protected static string $resource = ProductionRequestResource::class;
}
