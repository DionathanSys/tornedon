<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages;

use App\Filament\Clusters\Financial\Resources\ChartAccounts\ChartAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChartAccount extends EditRecord
{
    protected static string $resource = ChartAccountResource::class;

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
