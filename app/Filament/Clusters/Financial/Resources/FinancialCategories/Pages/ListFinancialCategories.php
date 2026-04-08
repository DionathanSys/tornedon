<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories\Pages;

use App\Filament\Clusters\Financial\Resources\FinancialCategories\FinancialCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinancialCategories extends ListRecords
{
    protected static string $resource = FinancialCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Categoria Financeira'),
        ];
    }
}
