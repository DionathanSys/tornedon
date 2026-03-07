<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProductionOrders extends ListRecords
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
