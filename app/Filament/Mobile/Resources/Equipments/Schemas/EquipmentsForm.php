<?php

namespace App\Filament\Mobile\Resources\Equipments\Schemas;

use App\Enum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EquipmentsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
            ])
            ->components([
                Section::make('Equipamento')
                    ->columnSpanFull()
                    ->columns([
                        'sm' => 1,
                    ])
                    ->compact()
                    ->schema([
                        Select::make('owner_id')
                            ->label('Proprietário')
                            ->native(false)
                            ->relationship(
                                'owner',
                                'name',
                                modifyQueryUsing: function (Builder $query) {
                                    $tenant = Filament::getTenant();

                                    return $query->whereHas('companies', function (Builder $subQuery) use ($tenant) {
                                        $subQuery->where('company_id', $tenant->id);
                                    });
                                }
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabledOn('edit')
                            ->columnSpanFull()
                            ->helperText('Quem é o dono do equipamento.'),

                        TextInput::make('name')
                            ->label('Descrição')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Nome fácil de identificar.')
                            ->autocomplete(false),

                        Select::make('type')
                            ->label('Tipo')
                            ->native(false)
                            ->required()
                            ->live()
                            ->selectablePlaceholder(false)
                            ->options(Enum\Equipment\Type::toSelectArray())
                            ->columnSpanFull()
                            ->helperText('Escolha a categoria do equipamento.'),

                        TextInput::make('placa')
                            ->label('Placa')
                            ->maxLength(7)
                            ->placeholder('ABC1234')
                            ->required(fn (Get $get) => $get('type') === Enum\Equipment\Type::CAR->value || $get('type') === Enum\Equipment\Type::TRUCK->value)
                            ->hiddenJs(<<<'JS'
                                $get('type') !== 'car' && $get('type') !== 'truck'
                            JS)
                            ->columnSpanFull()
                            ->helperText('Só para carro/caminhão.')
                            ->autocomplete(false),

                        TextInput::make('serial_number')
                            ->label('Número de Série')
                            ->maxLength(255)
                            ->required(fn (Get $get) => $get('type') !== Enum\Equipment\Type::CAR->value && $get('type') !== Enum\Equipment\Type::TRUCK->value)
                            ->hiddenJs(<<<'JS'
                                $get('type') === 'car' || $get('type') === 'truck'
                            JS)
                            ->columnSpanFull()
                            ->helperText('Use para tipos não veiculares.')
                            ->autocomplete(false),

                        TextInput::make('mark')
                            ->label('Marca')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Opcional.')
                            ->autocomplete(false),

                        TextInput::make('model')
                            ->label('Modelo')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('Opcional.')
                            ->autocomplete(false),
                    ]),
            ]);
    }
}

