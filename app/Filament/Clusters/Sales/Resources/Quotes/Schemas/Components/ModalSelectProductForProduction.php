<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ProductTable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\TableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

class ModalSelectProductForProduction
{
    public static function make(?string $field = 'item.product_id'): ModalTableSelect
    {
        return ModalTableSelect::make($field)
            ->label('Produto')
            ->saved(false)
            ->relationship('product', 'product_code')
            ->tableSelect(fn(TableSelect $select): TableSelect => $select->relationshipName(null))
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
