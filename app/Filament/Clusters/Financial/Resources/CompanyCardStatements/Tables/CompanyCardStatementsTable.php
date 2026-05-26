<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardStatements\Tables;

use App\Models\CompanyCardStatement;
use App\Models\CompanyCreditCard;
use App\Models\FinancialAccount;
use App\Notification\NotifyService as notify;
use App\Services\CompanyCard\CompanyCardStatementPaymentService;
use App\Services\CompanyCard\CompanyCardStatementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CompanyCardStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('companyCreditCard.name')
                    ->label('Cartão')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reference_month')
                    ->label('Competência')
                    ->date('m/Y')
                    ->sortable(),
                TextColumn::make('cutoff_date')
                    ->label('Corte')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('closing_date')
                    ->label('Fechamento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('gross_total')
                    ->label('Bruto')
                    ->money('BRL'),
                TextColumn::make('paid_total')
                    ->label('Pago')
                    ->money('BRL'),
                TextColumn::make('balance_total')
                    ->label('Saldo')
                    ->money('BRL'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => match ($state?->value ?? $state) {
                        'open' => 'warning',
                        'closed' => 'info',
                        'partial' => 'primary',
                        'paid' => 'success',
                        'canceled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('accountPayable.id')
                    ->label('AP')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '-')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('gerar')
                    ->label('Gerar')
                    ->action(function (CompanyCardStatement $record): void {
                        $service = app(CompanyCardStatementService::class);
                        $generated = $service->generateStatement($record->companyCreditCard, (string) $record->reference_month);

                        if ($service->hasError() || ! $generated) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        notify::success('Fatura regenerada com sucesso.');
                    }),
                Action::make('fechar')
                    ->label('Fechar')
                    ->color('success')
                    ->visible(fn (CompanyCardStatement $record): bool => $record->account_payable_id === null)
                    ->action(function (CompanyCardStatement $record): void {
                        $service = app(CompanyCardStatementService::class);
                        $closed = $service->closeStatement($record, (int) Auth::id());

                        if ($service->hasError() || ! $closed) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        notify::success('Fatura fechada e obrigação gerada com sucesso.');
                    }),
                Action::make('conciliar')
                    ->label('Conciliar')
                    ->action(function (CompanyCardStatement $record): void {
                        $service = app(CompanyCardStatementService::class);
                        $updated = $service->recalculateTotals($record);

                        notify::success('Fatura conciliada. Saldo atual: R$ '.number_format((float) $updated->balance_total, 2, ',', '.'));
                    }),
                Action::make('registrar_pagamento')
                    ->label('Registrar Pagamento')
                    ->color('primary')
                    ->visible(fn (CompanyCardStatement $record): bool => $record->account_payable_id !== null && (float) $record->balance_total > 0)
                    ->schema([
                        TextInput::make('amount')
                            ->label('Valor')
                            ->numeric()
                            ->required(),
                        DatePicker::make('payment_date')
                            ->label('Data do Pagamento')
                            ->required()
                            ->default(now()->format('Y-m-d')),
                        Select::make('financial_account_id')
                            ->label('Conta Financeira')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false),
                        TextInput::make('notes')
                            ->label('Observações')
                            ->maxLength(255),
                    ])
                    ->fillForm(fn (CompanyCardStatement $record): array => [
                        'amount' => round((float) $record->balance_total, 2),
                        'payment_date' => now()->format('Y-m-d'),
                        'financial_account_id' => $record->companyCreditCard?->default_financial_account_id
                            ?? FinancialAccount::defaultIdForCompany(Filament::getTenant()->id),
                    ])
                    ->action(function (CompanyCardStatement $record, array $data): void {
                        $service = app(CompanyCardStatementPaymentService::class);

                        $payment = $service->registerPayment(
                            $record,
                            (float) $data['amount'],
                            (string) $data['payment_date'],
                            [
                                'financial_account_id' => $data['financial_account_id'] ?? null,
                                'notes' => $data['notes'] ?? null,
                                'user_id' => (int) Auth::id(),
                            ]
                        );

                        if ($service->hasError() || ! $payment) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        notify::success('Pagamento da fatura registrado com sucesso.');
                    }),
            ])
            ->toolbarActions([
                Action::make('gerar_fatura')
                    ->label('Gerar Fatura')
                    ->color('success')
                    ->schema([
                        Select::make('company_credit_card_id')
                            ->label('Cartão')
                            ->options(fn (): array => CompanyCreditCard::query()
                                ->where('company_id', Filament::getTenant()->id)
                                ->where('active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),
                        DatePicker::make('reference_month')
                            ->label('Competência')
                            ->required()
                            ->default(now()->startOfMonth()->format('Y-m-d')),
                    ])
                    ->action(function (array $data): void {
                        $card = CompanyCreditCard::query()->find((int) $data['company_credit_card_id']);

                        if (! $card) {
                            notify::error(message: 'Cartão não encontrado.');

                            return;
                        }

                        $service = app(CompanyCardStatementService::class);
                        $statement = $service->generateStatement($card, (string) $data['reference_month']);

                        if ($service->hasError() || ! $statement) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        notify::success('Fatura gerada/atualizada com sucesso.');
                    }),
            ])
            ->defaultSort('reference_month', 'desc');
    }
}
