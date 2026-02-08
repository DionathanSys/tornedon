<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes\Schemas;

use App\Enum\Product\Origin;
use App\Filament\Components\NcmCodeInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ProductTaxForm
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
                        Select::make('product_id')
                            ->label('Produto')
                            ->relationship('product', 'name')
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->searchable()
                            ->required()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Nome')
                                    ->required(),
                            ]),
                    ]),
                Section::make('Informações Fiscais')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('product_origin')
                            ->label('Origem do Produto')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(Origin::toSelectArray())
                            ->native(false),
                        NcmCodeInput::make(),
                        TextInput::make('cest_code')
                            ->label('Código CEST')
                            ->columnSpan(['md' => 1, 'lg' => 3])
                            ->mask('99.999.99')
                            ->placeholder('00.000.00')
                            ->maxLength(9),
                    ]),
                Section::make('Impostos')
                    ->columns([
                        'sm' => 1,
                        'md' => 2,
                        'lg' => 2,
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        KeyValue::make('icms')
                            ->label('ICMS')
                            ->columnSpanFull()
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->addActionLabel('Adicionar campo')
                            ->deletable(fn() => Auth::user()->is_admin)
                            ->reorderable(),
                        KeyValue::make('ipi')
                            ->label('IPI')
                            ->columnSpanFull()
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->addActionLabel('Adicionar campo')
                            ->deletable(fn() => Auth::user()->is_admin)
                            ->reorderable(),
                        KeyValue::make('pis')
                            ->label('PIS')
                            ->columnSpanFull()
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->addActionLabel('Adicionar campo')
                            ->deletable(fn() => Auth::user()->is_admin)
                            ->reorderable(),
                        KeyValue::make('cofins')
                            ->label('COFINS')
                            ->columnSpanFull()
                            ->keyLabel('Chave')
                            ->valueLabel('Valor')
                            ->addActionLabel('Adicionar campo')
                            ->deletable(fn() => Auth::user()->is_admin)
                            ->reorderable(),
                    ]),
                Hidden::make('created_by'),
                Hidden::make('updated_by'),
            ]);
    }
}

//TODO: Implementar controle de permissão para deletar campos de impostos, permitindo apenas para usuários masters.
