<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\ProductTaxResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductTax extends CreateRecord
{
    protected static string $resource = ProductTaxResource::class;
}
