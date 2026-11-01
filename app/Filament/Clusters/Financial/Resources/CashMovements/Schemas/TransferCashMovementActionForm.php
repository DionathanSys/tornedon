<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements\Schemas;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Leandrocfe\FilamentPtbrFormFields\Money;

class TransferCashMovementActionForm
{
    public static function components(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Select::make('source_financial_account_id')
                    ->label('Conta de origem')
                    ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                    ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->required(),
                Select::make('destination_financial_account_id')
                    ->label('Conta de destino')
                    ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->different('source_financial_account_id')
                    ->disableOptionWhen(fn ($value, Get $get): bool => (string) $value === (string) $get('source_financial_account_id'))
                    ->validationMessages(['different' => 'Escolha uma conta de destino diferente da origem.'])
                    ->required(),
                SelectFinancialCategory::make('financial_category_id', 'cash_movement')
                    ->label('Categoria financeira')
                    ->columnSpanFull()
                    ->createOptionAction(fn (Action $action) => $action->authorize(fn () => Gate::allows('create', FinancialCategory::class)))
                    ->required(),
                DatePicker::make('transaction_date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                Money::make('amount')
                    ->label('Valor')
                    ->prefix('R$')
                    ->formatStateUsing(fn ($state) => is_numeric($state) ? number_format((float) $state, 2, ',', '.') : $state)
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
            ])->columnSpanFull(),
        ];
    }
}
