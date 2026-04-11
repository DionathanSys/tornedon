<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Enum\Tax\StateTaxIndicator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Leandrocfe\FilamentPtbrFormFields\Document;
use Leandrocfe\FilamentPtbrFormFields\Money;

class QuickCreateCustomerPartnerForm
{
    public static function schema(): array
    {
        return [
            Section::make('Parceiro')
                ->columns(['md' => 6, 'lg' => 12])
                ->schema([
                    Select::make('document_type')
                        ->label('Tipo de Doc.')
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->options([
                            'cpf' => 'CPF',
                            'cnpj' => 'CNPJ',
                        ])
                        ->default('cnpj')
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn ($state, callable $set) => $set('document_number', null)),
                    Document::make('document_number')
                        ->label('Nº do Doc.')
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->dynamic()
                        ->required(),
                    TextInput::make('name')
                        ->label('Nome')
                        ->columnSpan(['md' => 6, 'lg' => 6])
                        ->required()
                        ->maxLength(255)
                        ->autocomplete(false),
                    Select::make('state_tax_indicator')
                        ->label('Indicador IE')
                        ->columnSpan(['md' => 4, 'lg' => 6])
                        ->options(StateTaxIndicator::toSelectArray())
                        ->required()
                        ->native(false),
                    TextInput::make('state_tax_id')
                        ->label('Inscrição Estadual')
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->numeric()
                        ->autocomplete(false),
                    TextInput::make('municipal_tax_id')
                        ->label('Inscrição Municipal')
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->numeric()
                        ->autocomplete(false),
                    Toggle::make('import_cnpj_data')
                        ->label('Importar endereço e contato via CNPJ')
                        ->columnSpanFull()
                        ->inline(false)
                        ->default(false)
                        ->visible(fn (callable $get): bool => $get('document_type') === 'cnpj'),
                ]),
            Section::make('Vínculo')
                ->columns(['md' => 6, 'lg' => 12])
                ->schema([
                    Money::make('invoice_threshold')
                        ->label('Vlr. Min p/ Fatura')
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->default(0)
                        ->required(),
                    Money::make('customer_discount_percentage')
                        ->label('Desconto Cliente (%)')
                        ->suffix('%')
                        ->prefix(null)
                        ->columnSpan(['md' => 2, 'lg' => 3])
                        ->default(0),
                    Toggle::make('is_active')
                        ->label('Ativo')
                        ->columnSpan(['md' => 2, 'lg' => 2])
                        ->inline(false)
                        ->default(true),
                ]),
            Section::make('Alertas')
                ->columns(['md' => 6, 'lg' => 12])
                ->schema([
                    Grid::make()
                        ->columns(['md' => 6, 'lg' => 12])
                        ->columnSpanFull()
                        ->schema([
                            Toggle::make('notify_service_order_closed')
                                ->label('OS Encerrada')
                                ->columnSpan(['md' => 2, 'lg' => 2])
                                ->inline(false)
                                ->default(false),
                            Toggle::make('notify_requisition_closed')
                                ->label('Requisição Encerrada')
                                ->columnSpan(['md' => 2, 'lg' => 3])
                                ->inline(false)
                                ->default(false),
                            Toggle::make('notify_production_order_closed')
                                ->label('OP Encerrada')
                                ->columnSpan(['md' => 2, 'lg' => 2])
                                ->inline(false)
                                ->default(false),
                            Toggle::make('notify_invoice_confirmed')
                                ->label('Fatura Confirmada')
                                ->columnSpan(['md' => 2, 'lg' => 2])
                                ->inline(false)
                                ->default(false),
                            Toggle::make('notify_fiscal_document_confirmed')
                                ->label('NF Confirmada')
                                ->columnSpan(['md' => 2, 'lg' => 3])
                                ->inline(false)
                                ->default(false),
                        ]),
                ]),
        ];
    }
}
