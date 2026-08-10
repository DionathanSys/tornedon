<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels\Pages;

use App\Filament\Clusters\Financial\Resources\DreModels\DreModelResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDreModel extends CreateRecord
{
    protected static string $resource = DreModelResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
