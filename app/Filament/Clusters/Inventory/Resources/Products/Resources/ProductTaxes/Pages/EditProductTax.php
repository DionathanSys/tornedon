<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\ProductTaxResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductTax extends EditRecord
{
    protected static string $resource = ProductTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
