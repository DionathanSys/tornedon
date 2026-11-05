<?php

namespace App\Filament\Bpo\Resources\Products\Pages;

use App\Filament\Bpo\Resources\ProductResource;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends \App\Filament\Clusters\Inventory\Resources\Products\Pages\EditProduct
{
    protected static string $resource = ProductResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return ProductResource::getEloquentQuery()->findOrFail($key);
    }
}
