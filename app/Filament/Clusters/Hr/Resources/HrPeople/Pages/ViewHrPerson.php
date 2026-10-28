<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople\Pages;

use App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewHrPerson extends ViewRecord
{
    protected static string $resource = HrPersonResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
