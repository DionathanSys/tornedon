<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductTaxes\ProductTaxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductTaxes extends ListRecords
{
    protected static string $resource = ProductTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Cadastrar Imposto de Produto'),
        ];
    }
}
