<?php

namespace App\Filament\Mobile\Resources\MobileEquipments\Pages;

use App\Filament\Clusters\Partners\Resources\Equipments\Pages\EditEquipment;
use App\Filament\Mobile\Resources\MobileEquipments\MobileEquipmentResource;
use Filament\Actions\DeleteAction;

class EditMobileEquipment extends EditEquipment
{
    protected static string $resource = MobileEquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }
}
