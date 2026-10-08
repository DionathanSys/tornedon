<?php

namespace App\Filament\Clusters\Financial\Resources\Components;

use App\Models\FinancialAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

final class AutoReceiptFields
{
    public static function components(): array
    {
        return [
            Toggle::make('auto_register_receipt_on_due_date')
                ->label('Registrar recebimento automaticamente no vencimento?')
                ->default(false)
                ->live()
                ->columnSpanFull()
                ->helperText('A rotina diária registra somente o saldo em aberto de cada parcela.'),
            Select::make('auto_receipt_financial_account_id')
                ->label('Conta financeira do recebimento automático')
                ->options(fn (): array => FinancialAccount::optionsForCompany((int) Filament::getTenant()?->id))
                ->default(fn (): ?int => FinancialAccount::defaultIdForCompany((int) Filament::getTenant()?->id))
                ->searchable()->preload()->native(false)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => (bool) $get('auto_register_receipt_on_due_date'))
                ->required(fn (Get $get): bool => (bool) $get('auto_register_receipt_on_due_date')),
        ];
    }
}
