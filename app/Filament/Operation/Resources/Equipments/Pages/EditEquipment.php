<?php

namespace App\Filament\Operation\Resources\Equipments\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\Equipments\EquipmentResource;
use Filament\Resources\Pages\EditRecord;

class EditEquipment extends EditRecord
{
    use HasOperationResourcePage;

    protected static string $resource = EquipmentResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Equipamento atualizado com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
