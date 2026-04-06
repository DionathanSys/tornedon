<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class RegisterInstallmentPaymentAction
{
    public static function make(): Action
    {
        return Action::make('register_payment')
            ->label('Registrar recebimento')
            ->icon('heroicon-o-currency-dollar')
            ->color('success')
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    DatePicker::make('payment_date')
                        ->label('Data do recebimento')
                        ->columnSpan(1)
                        ->default(now())
                        ->required(),
                    Money::make('amount')
                        ->label('Valor recebido')
                        ->columnSpan(1)
                        ->default(fn (AccountReceivableInstallment $record) => $record->due_amount)
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->columnSpan(1),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->columnSpan(1),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->columnSpan(1),
                    TextInput::make('bank_account_id')
                        ->label('Conta bancaria (ID)')
                        ->numeric()
                        ->columnSpan(1),
                    Textarea::make('notes')
                        ->label('Observacoes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
            ->action(function (AccountReceivableInstallment $record, array $data): void {
                $service = app(AccountReceivableService::class);
                $payment = $service->registerInstallmentPayment(
                    $record,
                    (float) ($data['amount'] ?? 0),
                    (string) ($data['payment_date'] ?? ''),
                    [
                        'interest_amount' => (float) ($data['interest_amount'] ?? 0),
                        'fine_amount' => (float) ($data['fine_amount'] ?? 0),
                        'discount_amount' => (float) ($data['discount_amount'] ?? 0),
                        'bank_account_id' => $data['bank_account_id'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]
                );

                if ($service->hasError() || $payment === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao registrar recebimento da parcela.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Recebimento registrado com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
