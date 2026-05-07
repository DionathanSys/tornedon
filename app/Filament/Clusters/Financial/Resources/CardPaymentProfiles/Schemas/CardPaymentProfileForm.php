<?php

namespace App\Filament\Clusters\Financial\Resources\CardPaymentProfiles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CardPaymentProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 12,
            ])
            ->components([
                Section::make('Perfil de recebimento em cartao')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 12,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(120)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('brand')
                            ->label('Bandeira')
                            ->maxLength(60)
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        TextInput::make('acquirer')
                            ->label('Adquirente')
                            ->maxLength(120)
                            ->columnSpan(['md' => 1, 'lg' => 5]),
                        TextInput::make('fee_percent')
                            ->label('Taxa %')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->default(0)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        Money::make('fee_fixed')
                            ->label('Taxa fixa')
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        TextInput::make('settlement_days')
                            ->label('Prazo de liquidacao (dias)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(365)
                            ->default(0)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 4]),
                        Toggle::make('active')
                            ->label('Ativo')
                            ->inline(false)
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                    ]),
            ]);
    }
}
