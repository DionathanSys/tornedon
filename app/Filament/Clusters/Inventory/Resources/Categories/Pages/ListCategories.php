<?php

namespace App\Filament\Clusters\Inventory\Resources\Categories\Pages;

use App\Filament\Clusters\Inventory\Resources\Categories\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Categoria')
                ->icon(Heroicon::Plus)
                ->badge(),
        ];
    }
}
