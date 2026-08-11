<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\FinancialAccount;
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
                ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
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
            SelectFinancialCategory::make('financial_category_id', 'cash_movement')
                ->label('Categoria financeira')
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
                ->label('Descrição')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Observações')
                ->rows(3)
                ->columnSpanFull(),
        ];
    }
}
