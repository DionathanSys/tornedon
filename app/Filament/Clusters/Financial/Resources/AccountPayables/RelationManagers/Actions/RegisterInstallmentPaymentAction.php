<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

final class RegisterInstallmentPaymentAction
{
    public static function make(): Action
    {
        return Action::make('register_payment')
            ->label('Registrar pagamento')
            ->icon('heroicon-o-currency-dollar')
            ->color('success')
            ->schema([
                DatePicker::make('payment_date')
                    ->label('Data do pagamento')
                    ->required(),
                TextInput::make('amount')
                    ->label('Valor pago')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                TextInput::make('interest_amount')
                    ->label('Juros')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('fine_amount')
                    ->label('Multa')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('discount_amount')
                    ->label('Desconto')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('bank_account_id')
                    ->label('Conta bancária (ID)')
                    ->numeric(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3),
            ])
            ->action(function (AccountPayableInstallment $record, array $data): void {
                $service = app(AccountPayableService::class);
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
                        ->title($service->getMessageUser() ?: 'Erro ao registrar pagamento da parcela.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Pagamento registrado com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
