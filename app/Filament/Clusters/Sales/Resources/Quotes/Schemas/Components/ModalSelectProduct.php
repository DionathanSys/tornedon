<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Filament\Tables\ProductTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use Filament\Actions\Action;

class ModalSelectProduct
{
    public static function make(?string $field = 'product_id'): AutoSubmitModalTableSelect
    {
        return AutoSubmitModalTableSelect::make($field)
            ->label('Produto')
            ->saved()
            ->relationship('product', 'product_code')
            ->tableConfiguration(ProductTable::class)
            ->selectAction(
                fn(Action $action) => $action
                    ->label('Selecionar')
                    ->modalHeading('Buscar Produto')
                    ->modalSubmitActionLabel('Confirmar seleção'),
            );
    }
}
