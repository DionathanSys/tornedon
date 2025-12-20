<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Schemas;

use App\Enum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Document;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'sm' => 1,
                'md' => 4,
                'lg' => 6,
            ])
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->autocomplete(false)
                    ->columnSpanFull()
                    ->required(),
                Select::make('type')
                    ->label('Tipo')
                    ->columnStart(1)
                    ->columnSpan(['md' => 2, 'lg' => 2])
                    ->options(Enum\Partner\Type::toSelectArray())
                    ->native(false)
                    ->multiple()
                    ->default(Enum\Partner\Type::CUSTOMER->value)
                    ->required(),
                Select::make('document_type')
                    ->label('Tipo de Doc.')
                    ->columnSpan(['md' => 1, 'lg' => 1])
                    ->options([
                        'cpf' => 'CPF',
                        'cnpj' => 'CNPJ',
                    ])
                    ->default('cnpj')
                    ->native(false)
                    ->required(),
                Document::make('document_number')
                    ->label('Nº do Doc.')
                    ->columnSpan(['md' => 2, 'lg' => 3])
                    ->dynamic(),
                TextInput::make('state_tax_id')
                    ->label('Inscrição Estadual')
                    ->columnStart(1)
                    ->columnSpan(['md' => 2, 'lg' => 2])
                    ->autocomplete(false)
                    ->numeric(),
                Select::make('state_tax_indicator')
                    ->label('Indicador IE')
                    ->columnSpan(['md' => 2, 'lg' => 2])
                    ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                    ->native(false),
                TextInput::make('municipal_tax_id')
                    ->label('Inscrição Municipal')
                    ->autocomplete(false)
                    ->columnSpan(['md' => 2, 'lg' => 2])
                    ->numeric(),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->inline(false)
                    ->default(true)
                    ->columnStart(1)
                    ->required(),
            ]);
    }
}
