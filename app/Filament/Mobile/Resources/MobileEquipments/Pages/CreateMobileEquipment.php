<?php

namespace App\Filament\Mobile\Resources\MobileEquipments\Pages;

use App\Filament\Clusters\Partners\Resources\Equipments\Pages\CreateEquipment;
use App\Filament\Mobile\Resources\MobileEquipments\MobileEquipmentResource;

class CreateMobileEquipment extends CreateEquipment
{
    protected static string $resource = MobileEquipmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return parent::mutateFormDataBeforeCreate($data);
    }
}
