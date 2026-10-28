<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\Schemas;

use App\Models\FinancialAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class PaymentEditForm
{
    public static function components(): array
    {
        return [
            Section::make('Valor e data do recebimento')
                ->description('As alterações são aplicadas à baixa e à movimentação financeira vinculada.')
                ->compact()->columnSpanFull()->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Money::make('amount')->label('Valor recebido')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.'))->required(),
                    DatePicker::make('payment_date')->label('Data do recebimento')->displayFormat('d/m/Y')->required(),
                    Select::make('financial_account_id')->label('Conta financeira')
                        ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                        ->searchable()->preload()->native(false)->required(),
                ]),
            Section::make('Encargos e descontos')
                ->description('Ajustes considerados na composição do recebimento.')
                ->compact()->columnSpanFull()->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Money::make('interest_amount')->label('Juros')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                    Money::make('fine_amount')->label('Multa')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                    Money::make('discount_amount')->label('Desconto')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
                    TextInput::make('bank_account_id')->label('Conta bancária (ID)')->numeric()->visible(false),
                ]),
            Section::make('Detalhes')->compact()->columnSpanFull()->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Textarea::make('description')->label('Descrição do movimento')->rows(3)->maxLength(255),
                    Textarea::make('notes')->label('Observações')->rows(3),
                ]),
        ];
    }
}
