<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Pages\Actions;

use App\Filament\Clusters\Partners\Resources\Equipments\EquipmentResource;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class CreateEquipmentAction
{
    public static function make(): Action
    {
        return Action::make('create-equipment')
            ->label('Equipamento')
            ->icon(Heroicon::PlusCircle)
            ->badge()
            ->schema(function(Schema $schema) {
                return EquipmentResource::form($schema);
            });
    }
}