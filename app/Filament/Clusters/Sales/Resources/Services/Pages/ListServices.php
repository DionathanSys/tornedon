<?php

namespace App\Filament\Clusters\Sales\Resources\Services\Pages;

use App\Filament\Clusters\Sales\Resources\Services\ServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}
