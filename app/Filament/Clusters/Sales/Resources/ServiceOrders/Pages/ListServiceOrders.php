<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\ServiceOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListServiceOrders extends ListRecords
{
    protected static string $resource = ServiceOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ordem de Serviço')
                ->icon(Heroicon::Plus)
                ->badge(),
        ];
    }
}
