<?php

namespace App\Filament\Clusters\Partners\Resources\Addresses\Components;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

final class AddressComponent
{
    public static function make(): array
    {
        return [
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('postal_code')
                        ->label('CEP')
                        ->mask('99999-999')
                        ->required()
                        ->maxLength(9)
                        ->columnSpan(1),

                    TextInput::make('street')
                        ->label('Logradouro/Rua')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('number')
                        ->label('Número')
                        ->maxLength(20)
                        ->columnSpan(1),

                    TextInput::make('complement')
                        ->label('Complemento')
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('neighborhood')
                        ->label('Bairro')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    TextInput::make('city')
                        ->label('Cidade')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    TextInput::make('city_code')
                        ->label('Código IBGE')
                        ->maxLength(20)
                        ->columnSpan(1),
                ]),

            Grid::make(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('state')
                        ->label('Estado')
                        ->required()
                        ->options([
                            'AC' => 'Acre',
                            'AL' => 'Alagoas',
                            'AP' => 'Amapá',
                            'AM' => 'Amazonas',
                            'BA' => 'Bahia',
                            'CE' => 'Ceará',
                            'DF' => 'Distrito Federal',
                            'ES' => 'Espírito Santo',
                            'GO' => 'Goiás',
                            'MA' => 'Maranhão',
                            'MT' => 'Mato Grosso',
                            'MS' => 'Mato Grosso do Sul',
                            'MG' => 'Minas Gerais',
                            'PA' => 'Pará',
                            'PB' => 'Paraíba',
                            'PR' => 'Paraná',
                            'PE' => 'Pernambuco',
                            'PI' => 'Piauí',
                            'RJ' => 'Rio de Janeiro',
                            'RN' => 'Rio Grande do Norte',
                            'RS' => 'Rio Grande do Sul',
                            'RO' => 'Rondônia',
                            'RR' => 'Roraima',
                            'SC' => 'Santa Catarina',
                            'SP' => 'São Paulo',
                            'SE' => 'Sergipe',
                            'TO' => 'Tocantins',
                        ])
                        ->searchable()
                        ->columnSpan(1),

                    TextInput::make('country')
                        ->label('País')
                        ->default('Brasil')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),
                ]),
        ];
    }
}
