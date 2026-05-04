<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Pages;

use App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\CardPaymentProfileResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCardPaymentProfile extends CreateRecord
{
    protected static string $resource = CardPaymentProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }
}
