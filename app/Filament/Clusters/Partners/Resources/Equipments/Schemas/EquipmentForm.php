<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments\Schemas;

use App\Enum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EquipmentForm
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
                Section::make('Informações do Equipamento')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->compact()
                    ->schema([
                        Select::make('owner_id')
                            ->label('Proprietário')
                            ->columnSpan(['md' => 4, 'lg' => 4])
                            ->relationship(
                                'owner',
                                'name',
                                modifyQueryUsing: function (Builder $query) {
                                    $tenant = Filament::getTenant();
                                    return $query
                                        ->whereHas('companies', function (Builder $subQuery) use ($tenant) {
                                            $subQuery->where('company_id', $tenant->id);
                                        });
                                }
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Parceiro proprietário do equipamento'),
                        TextInput::make('name')
                            ->label('Descrição')
                            ->columnSpan(['md' => 4, 'lg' => 4])
                            ->required()
                            ->maxLength(255)
                            ->helperText('Identificação do equipamento, ex: "Caminhão 01", "Equipamento 02", etc.'),
                        Select::make('type')
                            ->label('Tipo')
                            ->columnStart(1)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->options(Enum\Equipment\Type::toSelectArray()),
                        TextInput::make('placa')
                            ->label('Placa')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(7)
                            ->mask('AAA9*99')
                            ->placeholder('ABC1234')
                            ->required(fn(Get $get) => $get('type') === Enum\Equipment\Type::CAR->value || $get('type') === Enum\Equipment\Type::TRUCK->value)
                            ->hiddenJs(<<<'JS'
                                $get('type') !== 'car' && $get('type') !== 'truck'
                            JS)
                            ->helperText('Formato: ABC1234 ou ABC1D34'),
                        TextInput::make('model')
                            ->label('Modelo')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(255),
                        TextInput::make('serial_number')
                            ->label('Número de Série')
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->maxLength(255)
                            ->helperText('Identificação única do equipamento'),
                    ]),
            ]);
    }
}
