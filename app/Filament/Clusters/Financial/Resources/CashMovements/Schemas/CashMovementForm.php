<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Enum\Financial\CashMovementDirection;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CashMovementForm
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
                Section::make('Movimento Financeiro')
                    ->columns([
                        'sm' => 1,
                        'md' => 4,
                        'lg' => 8,
                    ])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('financial_account_id')
                            ->label('Conta Financeira')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('financial_category_id')
                            ->label('Categoria Financeira')
                            ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'cash_movement'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 2, 'lg' => 4]),
                        Select::make('direction')
                            ->label('Direcao')
                            ->options(CashMovementDirection::toSelectArray())
                            ->native(false)
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        DatePicker::make('transaction_date')
                            ->label('Data')
                            ->default(now())
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        Money::make('amount')
                            ->label('Valor')
                            ->prefix('R$')
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 2]),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 3, 'lg' => 6]),
                        Textarea::make('notes')
                            ->label('Observacoes')
                            ->rows(3)
                            ->columnSpanFull(),
                        Hidden::make('company_id'),
                        Hidden::make('transfer_group_id'),
                    ]),
            ]);
    }
}
