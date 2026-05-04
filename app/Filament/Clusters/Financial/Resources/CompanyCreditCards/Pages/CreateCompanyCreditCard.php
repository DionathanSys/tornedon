<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Pages;

use App\Filament\Clusters\Financial\Resources\CompanyCreditCards\CompanyCreditCardResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyCreditCard extends CreateRecord
{
    protected static string $resource = CompanyCreditCardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
