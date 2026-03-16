<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use App\Services\ProductStock\ProductStockService;
use App\Traits\ParsesMoneyValues;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SchemaFormItemsNfe
{

    public static function make(string $context = 'create'): array
    {
        return [
            ModalSelectProductStock::make('product_id')
                ->label('Estoque do Produto')
                ->required()
                ->afterStateUpdated(function ($state, Set $set) {
                    if (! $state) return;

                    $productStockService = app(ProductStockService::class);
                    $productStock = $productStockService->find($state);

                    if (! $productStock) return;

                    $product = $productStock->product;

                    // $set('product_code', $product->product_code);
                    $set('description', $product->unit . '-' . $product->name);
                    $set('unit', $product->unit);
                    $set('unit_price',  $product->price ? number_format($product->price, 2, ',', '.') : null);
                    $set('total_price', $product->price ? number_format($product->price, 2, ',', '.') : null);
                }),

            Hidden::make('unit_of_measure')
                ->saved(true),

            TextInput::make('description')
                ->label('Descrição')
                ->maxLength(255)
                ->columnSpanFull(),

            ItemValueGroup::make([
                'totalAmountField' => 'total_price',
            ]),

            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('ncm_code')
                        ->label('NCM')
                        ->maxLength(8),
                    TextInput::make('cest_code')
                        ->label('CEST')
                        ->maxLength(9),
                    TextInput::make('cfop_code')
                        ->label('CFOP')
                        ->maxLength(4),
                ]),

            Section::make('Outros Valores')
                ->columns(3)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    Money::make('freight_amount')
                        ->label('Frete'),
                    Money::make('insurance_amount')
                        ->label('Seguro'),
                    Money::make('other_expenses_amount')
                        ->label('Outras'),
                ]),

            Textarea::make('additional_information')
                ->label('Informações Adicionais do Item')
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),
        ];
    }
}
