<?php

namespace App\Filament\Bpo\Resources\Products\Pages;

use App\Filament\Bpo\Resources\ProductResource;

class CreateProduct extends \App\Filament\Clusters\Inventory\Resources\Products\Pages\CreateProduct
{
    protected static string $resource = ProductResource::class;

    protected static string $layout = 'components.bpo.layout';
}
