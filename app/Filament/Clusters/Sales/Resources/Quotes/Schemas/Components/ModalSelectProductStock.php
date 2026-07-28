<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ProductsStockTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ModalSelectProductStock
{
    public static function make(?string $field = 'item.product_stock_id'): AutoSubmitModalTableSelect
    {
        return AutoSubmitModalTableSelect::make($field)
            ->label('Produto Em Estoque')
            ->saved(false)
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
