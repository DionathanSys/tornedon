<?php

namespace App\Filament\Management\Resources\BankAccountConnections\Pages;

use App\Filament\Management\Resources\BankAccountConnections\BankAccountConnectionResource;
use Filament\Resources\Pages\EditRecord;

class EditBankAccountConnection extends EditRecord
{
    protected static string $resource = BankAccountConnectionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['credentials'] = BankAccountConnectionResource::credentialsFromFormData(
            $data,
            (array) ($this->record->credentials ?? []),
        );

        return $data;
    }
}
