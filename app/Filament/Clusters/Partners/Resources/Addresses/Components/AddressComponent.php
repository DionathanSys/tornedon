<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Components;

use Filament\Forms\Components\TextInput;

final class AddressComponent
{
    public static function make(): array
    {
        return [
            TextInput::make('street')
                ->label('Logradouro')
                ->columnStart(1)
                ->columnSpan([
                    'sm' => 1,
                    'md' => 4,
                    'lg' => 4,
                ])
                ->required()
                ->maxLength(255),
            TextInput::make('number')
                ->label('Número')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ])
                ->required()
                ->maxLength(50),
            TextInput::make('complement')
                ->label('Complemento')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 2,
                ]),
            TextInput::make('neighborhood')
                ->label('Bairro')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ]),
            TextInput::make('city')
                ->label('Cidade')
                ->required()
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ]),
            TextInput::make('state')
                ->label('Estado')
                ->required()
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ]),
            TextInput::make('country')
                ->label('País')
                ->required()
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ])
                ->default('BRASIL'),
            TextInput::make('postal_code')
                ->label('CEP')
                ->required()
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ]),
            TextInput::make('city_code')
                ->label('Código do IBGE da Cidade')
                ->columnSpan([
                    'sm' => 1,
                    'md' => 2,
                    'lg' => 4,
                ]),
        ];
    }
}
