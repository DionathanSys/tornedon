<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Tables\ProductsStockTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Models\ProductStock;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Facades\Filament;

class ModalSelectProductStock
{
    public static function make(?string $field = 'item.product_stock_id'): AutoSubmitModalTableSelect
    {
        return AutoSubmitModalTableSelect::make($field)
            ->label('Produto Em Estoque')
            ->saved(false)
            ->getOptionLabelUsing(function ($value): ?string {
                if (! filled($value)) {
                    return null;
                }

                $stock = ProductStock::query()
                    ->with('product')
                    ->where('company_id', Filament::getTenant()->id)
                    ->find((int) $value);

                if (! $stock) {
                    return null;
                }

                $code = $stock->product?->product_code;
                $name = $stock->product?->name ?? 'Produto sem nome';

                return $code ? "[{$code}] {$name}" : $name;
            })
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
