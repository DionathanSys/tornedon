<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Pages;

use App\Filament\Clusters\Partners\Resources\Equipments\EquipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEquipments extends ListRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Equipamento')
                ->icon(Heroicon::Plus),
        ];
    }
}
