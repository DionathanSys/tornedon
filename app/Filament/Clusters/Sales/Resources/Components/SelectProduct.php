<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Services\Product\ProductSalePriceService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SelectProduct
{
    public static function make(): Select
    {
        return Select::make('product_id')
            ->label('Peça')
            ->searchable()
            ->relationship('product', 'name', function ($query) {
                $query->where('products.company_id', Filament::getTenant()->id);
            })
            ->required()
            ->columnSpanFull()
            ->live(onBlur: true)
            ->afterStateUpdated(function (Set $set, Get $get, $state) {
                $service = app(ProductSalePriceService::class);

                $salePrice = $service->resolveById((int) $state);
                if ($salePrice !== null) {
                    $set('unit_price', number_format($salePrice, 2, ',', '.'));
                } else {
                    $set('unit_price', null);
                }

                // Preço mínimo de venda via service (sem acesso direto ao Model)
                $set('_min_sale_price', $service->getMinSalePriceById((int) $state));
            });
    }
}
