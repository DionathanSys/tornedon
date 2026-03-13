<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Pages;

use App\Filament\Clusters\Partners\Resources\Equipments\EquipmentResource;
use App\Filament\Shared\Actions\ReplicateToCompaniesAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReplicateToCompaniesAction::make('replicate'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Equipamento atualizado com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
