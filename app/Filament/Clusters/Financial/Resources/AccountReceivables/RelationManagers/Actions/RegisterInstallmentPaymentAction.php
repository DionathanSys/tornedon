<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Models\AccountReceivableInstallment;
use App\Models\FinancialAccount;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
                        ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                        ->columnSpan(1),
                    TextInput::make('bank_account_id')
                        ->label('Conta bancaria (ID)')
                        ->visible(false)
                        ->numeric()
                        ->columnSpan(1),
                    Select::make('financial_account_id')
                        ->label('Conta Financeira')
                        ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                        ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->columnSpan(1),
                    Textarea::make('description')
                        ->label('Descricao do Movimento')
                        ->rows(2)
                        ->default(fn (AccountReceivableInstallment $record) => $record->description ?? $record->accountReceivable?->description)
                        ->maxLength(255)
                        ->columnSpanFull(),
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
                        'financial_account_id' => $data['financial_account_id'] ?? null,
                        'description' => $data['description'] ?? null,
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
            })
            ->after(function (\Livewire\Component $livewire): void {
                $livewire->dispatch('refresh-installments');
                $livewire->dispatch('refresh-payments');
            });
    }
}
