<?php

namespace App\Filament\Clusters\Financial\Resources\CardInstitutions\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CardInstitutionForm
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
                Section::make('Instituição de cartão')
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
                        TextInput::make('settlement_days')
                            ->label('Prazo de repasse (dias corridos)')
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
                        Toggle::make('is_default')
                            ->label('Padrão para recebimentos em cartão')
                            ->default(false)
                            ->helperText('Substitui a instituição padrão anterior desta empresa.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
