<?php

namespace App\Filament\Clusters\Financial\Resources\ResultCenters\Pages;

use App\Filament\Clusters\Financial\Resources\ResultCenters\ResultCenterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditResultCenter extends EditRecord
{
    protected static string $resource = ResultCenterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
