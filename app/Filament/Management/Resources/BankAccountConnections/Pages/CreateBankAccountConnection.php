<?php

namespace App\Filament\Management\Resources\BankAccountConnections\Pages;

use App\Filament\Management\Resources\BankAccountConnections\BankAccountConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankAccountConnection extends CreateRecord
{
    protected static string $resource = BankAccountConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['credentials'] = BankAccountConnectionResource::credentialsFromFormData($data);

        return $data;
    }
}
