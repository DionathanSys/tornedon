<?php

namespace App\Filament\Clusters\Financial\Resources\CostCenters\Pages;

use App\Filament\Clusters\Financial\Resources\CostCenters\CostCenterResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCostCenter extends CreateRecord
{
    protected static string $resource = CostCenterResource::class;

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
