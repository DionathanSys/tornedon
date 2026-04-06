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
                        ->default(fn(AccountReceivableInstallment $record) => $record->due_amount)
                        ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    TextInput::make('bank_account_id')
                        ->label('Conta bancária (ID)')
                        ->visible(false)
                        ->numeric()
                        ->columnSpan(1),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
            ->action(function (AccountReceivableInstallment $record, array $data): void {
                $service = app(AccountReceivableService::class);
                $payment = $service->registerInstallmentPayment(
                    $record,
                    self::normalizeMoney($data['amount'] ?? 0),
                    (string) ($data['payment_date'] ?? ''),
                    [
                        'interest_amount' => self::normalizeMoney($data['interest_amount'] ?? 0),
                        'fine_amount' => self::normalizeMoney($data['fine_amount'] ?? 0),
                        'discount_amount' => self::normalizeMoney($data['discount_amount'] ?? 0),
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

    private static function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^\d,.-]/', '', $value) ?? '0';

            if (str_contains($normalized, ',')) {
                return (float) str_replace(',', '.', str_replace('.', '', $normalized));
            }

            if (preg_match('/^-?\d+$/', $normalized) === 1) {
                return ((float) $normalized) / 100;
            }

            return (float) $normalized;
        }

        if (is_int($value)) {
            return $value / 100;
        }

        if (is_float($value) && floor($value) === $value) {
            return $value / 100;
        }

        return (float) $value;
    }
}
