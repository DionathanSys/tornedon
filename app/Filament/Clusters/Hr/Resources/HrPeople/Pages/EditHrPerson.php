<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople\Pages;

use App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource;
use App\Services\Hr\HrService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditHrPerson extends EditRecord
{
    protected static string $resource = HrPersonResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->record = app(HrService::class)->savePerson(auth()->user(), Filament::getTenant(), $data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
