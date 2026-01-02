<?php

namespace App\Filament\Clusters\Partners\Resources\CompanyPartners\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use App\Enum;
use App\Filament\Clusters\Partners\Resources\Components\DocumentNumberInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Leandrocfe\FilamentPtbrFormFields\Document;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompanyPartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Parceiro')
                    ->disabledOn('edit')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
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
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->autocomplete(false)
                            ->numeric(),
                        TextInput::make('municipal_tax_id')
                            ->label('Inscrição Municipal')
                            ->autocomplete(false)
                            ->columnSpan(['md' => 2, 'lg' => 2])
                            ->numeric(),
                        Select::make('state_tax_indicator')
                            ->label('Indicador IE')
                            ->columnSpanFull()
                            ->options(Enum\Tax\StateTaxIndicator::toSelectArray())
                            ->native(false),

                    ]),
                Section::make('Configurações da Empresa')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('company_partner.type')
                            ->label('Tipo')
                            ->columnStart(1)
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->options(Enum\Partner\Type::toSelectArray())
                            ->native(false)
                            ->multiple()
                            ->default(Enum\Partner\Type::CUSTOMER->value)
                            ->required(),
                        Money::make('company_partner.invoice_threshold')
                            ->label('Vlr. Mín p/ Fatura')
                            ->columnSpan(['md' => 2, 'lg' => 3])
                            ->default(0),
                        Toggle::make('company_partner.is_active')
                            ->label('Ativo')
                            ->columnSpan(2)
                            ->inline(false)
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }
}
