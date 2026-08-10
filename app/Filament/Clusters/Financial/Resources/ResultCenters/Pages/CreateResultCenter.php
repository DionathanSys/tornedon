<?php

namespace App\Filament\Clusters\Financial\Resources\ResultCenters\Pages;

use App\Filament\Clusters\Financial\Resources\ResultCenters\ResultCenterResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateResultCenter extends CreateRecord
{
    protected static string $resource = ResultCenterResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
