<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages;

use App\Filament\Clusters\Financial\Resources\ChartAccounts\ChartAccountResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateChartAccount extends CreateRecord
{
    protected static string $resource = ChartAccountResource::class;

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
