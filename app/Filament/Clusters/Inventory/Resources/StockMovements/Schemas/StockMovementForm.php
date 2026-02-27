<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Schemas;

use App\Enum\StockMovement\Type;
use Filament\Schemas\Components\DateTimePickerInput;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\User;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 2,
                'lg' => 6,
            ])
            ->components([
                Section::make('Dados da Movimentação')
                    ->columns([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 6,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('type')
                            ->label('Tipo de Movimento')
                            ->options(collect(Type::cases())->mapWithKeys(fn($type) => [$type->value => $type->label()])->toArray())
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Select::make('product_stock_id')
                            ->label('Produto')
                            ->relationship('productStock', 'id')
                            ->getOptionLabelFromRecordUsing(fn($record) => $record->product->name)
                            ->searchable()
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Hidden::make('product_id')
                            ->default(null),
                        Money::make('quantity')
                            ->label('Quantidade')
                            ->required()
                            ->prefix(null)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Money::make('unit_price')
                            ->label('Custo Unitário')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Money::make('total_amount')
                            ->label('Custo Total')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('reason')
                            ->label('Motivo')
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('reference_type')
                            ->label('Tipo de Referência')
                            ->placeholder('Ex: requisição, ordem de produção')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('reference_id')
                            ->label('ID de Referência')
                            ->numeric()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Textarea::make('observations')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
