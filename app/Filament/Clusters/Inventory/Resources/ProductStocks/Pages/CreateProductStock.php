<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages;

use App\Filament\Clusters\Inventory\Resources\ProductStocks\ProductStockResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProductStock extends CreateRecord
{
    protected static string $resource = ProductStockResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['created_by'] = Auth::id();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
