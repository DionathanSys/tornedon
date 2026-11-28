<?php

namespace App\Filament\Operation\Resources\Equipments\Pages;

use App\Filament\Operation\Concerns\HasOperationResourcePage;
use App\Filament\Operation\Resources\Equipments\EquipmentResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateEquipment extends CreateRecord
{
    use HasOperationResourcePage;

    protected static string $resource = EquipmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->getKey();
        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Equipamento cadastrado com sucesso';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, ['owner_id']);
    }
}
