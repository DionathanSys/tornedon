<?php

namespace App\Filament\Operation\Resources\Equipments\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\Equipments\EquipmentResource;
use Filament\Resources\Pages\ListRecords;

class ListEquipments extends ListRecords
{
    use HasOperationResourcePage;

    protected static string $resource = EquipmentResource::class;
}
