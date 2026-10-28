<?php

namespace App\Filament\Clusters\Hr\Resources\HrPeople\Pages;

use App\Filament\Clusters\Hr\Resources\HrPeople\HrPersonResource;
use App\Services\Hr\HrService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHrPerson extends CreateRecord
{
    protected static string $resource = HrPersonResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(HrService::class)->savePerson(auth()->user(), Filament::getTenant(), $data);
    }
}
