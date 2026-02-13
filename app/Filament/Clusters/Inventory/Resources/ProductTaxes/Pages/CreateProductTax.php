<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductTaxes\ProductTaxResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProductTax extends CreateRecord
{
    protected static string $resource = ProductTaxResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
    
}
