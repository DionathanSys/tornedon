<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListRequisitions extends ListRecords
{
    protected static string $resource = RequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
