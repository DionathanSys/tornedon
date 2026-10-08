<?php

namespace App\Filament\Clusters\Financial\Resources\CardInstitutions\Pages;

use App\Filament\Clusters\Financial\Resources\CardInstitutions\CardInstitutionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCardInstitution extends CreateRecord
{
    protected static string $resource = CardInstitutionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
