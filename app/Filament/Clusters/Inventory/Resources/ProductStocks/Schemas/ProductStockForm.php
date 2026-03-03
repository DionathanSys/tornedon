<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Schemas;

use App\Enum\Product\OriginSalePrice;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ProductStockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 8,
            ])
            ->components([
                Section::make('Produto')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('Produto')
                            ->columnSpanFull(),
                        TextEntry::make('product.origin_sale_price')
                            ->label('Modo Precificação')
                            ->formatStateUsing(fn(OriginSalePrice $state) => $state->description())
                            ->badge()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextEntry::make('product.min_sale_price')
                            ->label('Preço Mínimo de Venda')
                            ->money('BRL', 100)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextEntry::make('product.sale_price_value')
                            ->label('Preço Fixo de Venda')
                            ->money('BRL', 100)
                            ->placeholder('Não definido')
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                    ]),
                Section::make('Quantidades')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity_available')
                            ->label('Quantidade Disponível')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->step(0.001)
                            ->required(),
                        TextInput::make('quantity_reserved')
                            ->label('Quantidade Reservada')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->step(0.001)
                            ->required(),
                        TextInput::make('quantity_minimum')
                            ->label('Estoque Mínimo')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->step(0.001)
                            ->required(),
                        TextInput::make('quantity_maximum')
                            ->label('Estoque Máximo')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001),
                    ]),
                Section::make('Custos e Preços')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Money::make('average_cost')
                            ->label('Custo Médio Unitário')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2, ',', '.') : 'R$ 0,00')
                            ->default(0)
                            ->prefix('R$'),
                        Money::make('last_cost')
                            ->label('Último Custo de Compra')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2, ',', '.') : 'R$ 0,00')
                            ->prefix('R$'),
                        Money::make('last_sale_price')
                            ->label('Último Preço de Venda')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2, ',', '.') : 'R$ 0,00')
                            ->prefix('R$'),
                    ]),
                Section::make('Último Movimento')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        DatePicker::make('last_movement_date')
                            ->label('Data do Último Movimento')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Select::make('last_movement_type')
                            ->label('Tipo do Último Movimento')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->options([
                                'entrada' => 'Entrada',
                                'saida' => 'Saída',
                                'ajuste' => 'Ajuste',
                                'transferencia' => 'Transferência',
                            ])
                            ->native(false),
                    ]),
                Section::make('Configurações')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Checkbox::make('is_active')
                            ->label('Ativo')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->inline(false)
                            ->default(true),
                        Checkbox::make('allow_negative')
                            ->label('Permite Estoque Negativo')
                            ->columnSpan(['md' => 2, 'lg' => 4])
                            ->inline(false)
                            ->default(false),
                    ]),
                Hidden::make('company_id'),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}
