<?php

namespace App\Filament\Clusters\Financial\Resources\DreModels\Pages;

use App\Filament\Clusters\Financial\Resources\DreModels\DreModelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDreModels extends ListRecords
{
    protected static string $resource = DreModelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
