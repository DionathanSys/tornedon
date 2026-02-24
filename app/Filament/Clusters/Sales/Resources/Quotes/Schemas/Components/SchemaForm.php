<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Filament\Tables\ProductsStockTable;
use App\Filament\Tables\ProductTable;
use App\Filament\Tables\ServiceTable;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductService;
use App\Services\ProductStock\ProductStockService;
use App\Services\Service\ServiceService;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SchemaForm
{
    use ParsesMoneyValues;
    
    public static function configure(): array
    {
        return [
            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    ModalTableSelect::make('product_stock_id')
                        ->label('Produto Em Estoque')
                        ->relationship('productStock', 'product.name')
                        ->tableConfiguration(ProductsStockTable::class)
                        ->selectAction(
                            fn(Action $action) => $action
                                ->label('Selecionar')
                                ->modalHeading('Buscar Produto')
                                ->modalSubmitActionLabel('Confirmar seleção'),
                        )
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $productStock = app(ProductStockService::class)->find($state);
                            if ($state) {
                                $salePrice = app(ProductSalePriceService::class)->resolve($productStock->product_id);
                                $unitOfMeasure = app(ProductService::class)->getUnitOfMeasure($productStock->product_id);
                                $set('service_id', null);
                                $set('product_id', null);
                                $set('product_stock_id', $productStock->product_id);
                                $set('destination', 'Requisição de Venda');
                                $set('unit_of_measure', $unitOfMeasure->value);
                                $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }
                        }),
                    ModalTableSelect::make('product_id')
                        ->label('Produto')
                        ->relationship('product', 'name')
                        ->tableConfiguration(ProductTable::class)
                        ->selectAction(
                            fn(Action $action) => $action
                                ->label('Selecionar')
                                ->modalHeading('Buscar Produto')
                                ->modalSubmitActionLabel('Confirmar seleção'),
                        )
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if ($state) {
                                $salePrice = app(ProductSalePriceService::class)->resolve($state);
                                $unitOfMeasure = app(ProductService::class)->getUnitOfMeasure($state);
                                $set('service_id', null);
                                $set('product_stock_id', null);
                                $set('destination', 'Ordem de Produção');
                                $set('unit_of_measure', $unitOfMeasure->value);
                                $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }
                        }),
                    ModalTableSelect::make('service_id')
                        ->label('Serviço')
                        ->relationship('service', 'name')
                        ->tableConfiguration(ServiceTable::class)
                        ->selectAction(
                            fn(Action $action) => $action
                                ->label('Selecionar')
                                ->modalHeading('Buscar Serviço')
                                ->modalSubmitActionLabel('Confirmar seleção'),
                        )
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if ($state) {
                                $salePrice = app(ServiceService::class)->getSalePrice($state);
                                $set('product_id', null);
                                $set('product_stock_id', null);
                                $set('destination', 'Ordem de Serviço');
                                $set('unit_of_measure', null);
                                $set('unit_price', number_format($salePrice, 2, ',', '.'));
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            }
                        }),
                ]),
            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('item_id'),
                    TextEntry::make('name')
                        ->label('Item')
                        ->disabled()
                        ->columnSpanFull(),
                    TextInput::make('unit_of_measure')
                        ->label('Unidade de Medida')
                        ->disabled()
                        ->columnSpan(1),
                    TextInput::make('destination')
                        ->label('Finalidade')
                        ->required()
                        ->disabled()
                        ->columnSpan(2),
                ]),
            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('quantity')
                        ->label('Quantidade')
                        ->required()
                        ->numeric()
                        ->default(1)
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $set('discount_amount', number_format(0, 2, ',', '.'));
                            $set('discount_percentage', number_format(0, 2, ',', '.'));
                            self::calculateValues($get, $set);
                        }),
                    Money::make('unit_price')
                        ->label('Preço Unitário')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $set('discount_amount', number_format(0, 2, ',', '.'));
                            $set('discount_percentage', number_format(0, 2, ',', '.'));
                            self::calculateValues($get, $set);
                        }),
                    Money::make('subtotal')
                        ->label('Subtotal')
                        ->readOnly(),
                ]),
            Group::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Money::make('discount_percentage')
                        ->label('Desconto (%)')
                        ->suffix('%')
                        ->prefix(null)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, callable $get) {
                            $subtotal = self::parseMoneyValue($get('subtotal'));
                            $percentage = self::parseMoneyValue($state);
                            $discountAmount = $subtotal * ($percentage / 100);
                            $set('discount_amount', number_format($discountAmount, 2, ',', '.'));
                            self::calculateValues($get, $set);
                        })
                        ->afterLabel(Action::make('reset_discount_percentage')
                            ->label('')
                            ->icon(Heroicon::ArrowPath)
                            ->action(function (Set $set, Get $get) {
                                $set('discount_percentage', number_format(0, 2, ',', '.'));
                                $set('discount_amount', number_format(0, 2, ',', '.'));
                                self::calculateValues($get, $set);
                            })),
                    Money::make('discount_amount')
                        ->label('Desconto (R$)')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, callable $get) {
                            $subtotal = self::parseMoneyValue($get('subtotal'));
                            $discountAmount = self::parseMoneyValue($state);
                            if ($subtotal > 0) {
                                $percentage = ($discountAmount / $subtotal) * 100;
                                $set('discount_percentage', number_format($percentage, 2, ',', '.'));
                            }
                            self::calculateValues($get, $set);
                        }),
                    Money::make('total_amount')
                        ->label('Valor Total')
                        ->readOnly(),
                ]),
            Textarea::make('observations')
                ->label('Observações')
                ->columnSpanFull(),
        ];
    }

    protected static function calculateValues(callable $get, Set $set): void
    {
    }
}
