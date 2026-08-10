<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels\Pages;

use App\Filament\Clusters\Financial\Resources\DreModels\DreModelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDreModel extends EditRecord
{
    protected static string $resource = DreModelResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
