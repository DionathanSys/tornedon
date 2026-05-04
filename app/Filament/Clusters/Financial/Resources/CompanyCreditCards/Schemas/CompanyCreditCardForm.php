<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCreditCards\Schemas;

use App\Models\FinancialAccount;
use App\Models\Partner;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompanyCreditCardForm
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
                Section::make('Cartão Corporativo')
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
                        TextInput::make('issuer')
                            ->label('Emissor')
                            ->maxLength(120)
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('issuer_partner_id')
                            ->label('Parceiro Emissor')
                            ->options(fn (): array => Partner::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        TextInput::make('last_four')
                            ->label('Últimos 4 dígitos')
                            ->maxLength(4)
                            ->minLength(4)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Money::make('credit_limit')
                            ->label('Limite de Crédito')
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('closing_day')
                            ->label('Dia Fechamento')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('due_day')
                            ->label('Dia Vencimento')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('statement_cutoff_business_days')
                            ->label('Antecedência Corte (dias úteis)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(10)
                            ->required()
                            ->helperText('Define quantos dias úteis antes do fechamento oficial a fatura deixa de receber novas transações.')
                            ->columnSpan(['md' => 1, 'lg' => 3]),
                        Select::make('default_financial_account_id')
                            ->label('Conta Financeira Padrão')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('Será sugerida automaticamente no pagamento da fatura, com possibilidade de troca na baixa.')
                            ->columnSpan(['md' => 2, 'lg' => 5]),
                        Toggle::make('active')
                            ->label('Ativo')
                            ->default(true)
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                    ]),
            ]);
    }
}
