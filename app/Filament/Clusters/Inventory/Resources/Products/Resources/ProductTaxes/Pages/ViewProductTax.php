<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\ProductTaxResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductTax extends ViewRecord
{
    protected static string $resource = ProductTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
