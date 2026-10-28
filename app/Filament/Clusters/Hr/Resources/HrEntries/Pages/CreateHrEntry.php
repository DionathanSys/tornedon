<?php

namespace App\Filament\Clusters\Hr\Resources\HrEntries\Pages;

use App\Filament\Clusters\Hr\Resources\HrEntries\HrEntryResource;
use App\Services\Hr\HrService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHrEntry extends CreateRecord
{
    protected static string $resource = HrEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(HrService::class)->saveEntry(auth()->user(), Filament::getTenant(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return HrEntryResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
