<?php

namespace App\Filament\Mobile\Resources\Equipments\Pages;

use App\Filament\Clusters\Partners\Resources\Equipments\Pages\ListEquipments;
use App\Filament\Mobile\Resources\Equipments\MobileEquipmentResource;

class ListMobileEquipments extends ListEquipments
{
    protected static string $resource = MobileEquipmentResource::class;
}
