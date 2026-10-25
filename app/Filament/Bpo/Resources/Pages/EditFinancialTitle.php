<?php

namespace App\Filament\Bpo\Resources\Pages;

use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class EditFinancialTitle extends EditRecord
{
    protected static string $layout = 'components.bpo.layout';

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['company_id'] = Filament::getTenant()->id;
        $service = static::getResource()::service();
        $updated = $service->update($record, $data, auth()->id());
        if (! $updated || $service->hasError()) {
            throw ValidationException::withMessages($service->getErrors() ?: ['data' => $service->getMessageUser()]);
        }

        return $updated;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
