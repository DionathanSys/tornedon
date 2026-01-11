<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Actions;

use App\Enum;
use App\Filament\Clusters\Partners\Resources\Components\DocumentNumberInput;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class UpdatePartner
{
    public static function make(): Action
    {
        return Action::make('edit-partner')
            ->label('Editar Parceiro')
            ->icon(Heroicon::PencilSquare)
            ->visible(fn($operation): bool => $operation === 'edit')
            ->schema(function (Schema $schema) {
                return $schema
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->schema([
                        Select::make('document_type')
                            ->label('Tipo de Doc.')
                            ->columnSpan(['md' => 1, 'lg' => 2])
                            ->options([
                                'cpf' => 'CPF',
                                'cnpj' => 'CNPJ',
                            ])
                            ->default('cnpj')
                            ->native(false)
                            ->required(),
                        DocumentNumberInput::make(),
                        TextInput::make('name')
                            ->label('Nome')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 4, 'lg' => 8])
                            ->required(),
                        TextInput::make('state_tax_id')
                            ->label('Inscrição Estadual')
                            ->placeholder('Não definido')
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->autocomplete(false)
                            ->numeric(),
                        TextInput::make('municipal_tax_id')
                            ->label('Inscrição Municipal')
                            ->placeholder('Não definido')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric(),
                        Select::make('state_tax_indicator')
                            ->label('Indicador IE')
                            ->columnSpanFull()
                            ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                            ->native(false),
                    ]);
            })
            ->action(fn(array $data) => dd($data))
            ;
    }
}
