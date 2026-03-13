<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ProductsStockTable;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ModalSelectProductStock
{
    public static function make(?string $field = 'item.product_stock_id'): ModalTableSelect
    {
        return ModalTableSelect::make($field)
            ->label('Produto Em Estoque')
            ->saved(false)
            ->relationship('productStock', 'product.product_code')
            ->tableConfiguration(ProductsStockTable::class)
            ->selectAction(
                fn(Action $action) => $action
                    ->label('Selecionar')
                    ->modalHeading('Buscar Produto em Estoque')
                    ->modalSubmitActionLabel('Confirmar seleção'),
            )
            ->afterStateUpdated(
                fn($state, Set $set, Get $get) => SchemaForm::resolveItem($set, $get, Destination::REQUISITION, $state)
            );
    }
}
