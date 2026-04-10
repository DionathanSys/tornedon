<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Leandrocfe\FilamentPtbrFormFields\Money;

class TransferCashMovementActionForm
{
    public static function components(): array
    {
        return [
            Select::make('source_financial_account_id')
                ->label('Conta de origem')
                ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),
            Select::make('destination_financial_account_id')
                ->label('Conta de destino')
                ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),
            Select::make('financial_category_id')
                ->label('Categoria financeira')
                ->options(fn (): array => FinancialCategory::optionsForCompany(Filament::getTenant()->id, 'cash_movement'))
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),
            DatePicker::make('transaction_date')
                ->label('Data')
                ->default(now())
                ->required(),
            Money::make('amount')
                ->label('Valor')
                ->prefix('R$')
                ->required(),
            TextInput::make('description')
                ->label('Descricao')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Observacoes')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }
}
