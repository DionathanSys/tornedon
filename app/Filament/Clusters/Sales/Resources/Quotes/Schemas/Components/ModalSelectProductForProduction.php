<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ProductTable;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ModalSelectProductForProduction
{
    public static function make(): ModalTableSelect
    {
        return ModalTableSelect::make('item.product_id')
            ->label('Produto')
            ->saved(false)
            ->relationship('product', 'product_code')
            ->tableConfiguration(ProductTable::class)
            ->selectAction(
                fn(Action $action) => $action
                    ->label('Selecionar')
                    ->modalHeading('Buscar Produto p/ Produção')
                    ->modalSubmitActionLabel('Confirmar seleção'),
            )
            ->afterStateUpdated(
                fn($state, Set $set, Get $get) => SchemaForm::resolveItem($set, $get, Destination::ORDER_PRODUCTION, $state)
            );
    }
}
