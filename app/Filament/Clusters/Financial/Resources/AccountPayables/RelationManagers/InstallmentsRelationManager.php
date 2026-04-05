<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Parcelas';

    protected static ?string $modelLabel = 'Parcela';

    protected static ?string $pluralModelLabel = 'Parcelas';

    protected static string|BackedEnum|null $icon = Heroicon::QueueList;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence_number')
            ->columns([
                TextColumn::make('sequence_number')
                    ->label('Parcela')
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('original_amount')
                    ->label('Valor Original')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->label('Valor Atual')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Valor Pago')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('balance_amount')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable(),
                TextColumn::make('paid_date')
                    ->label('Data Pgto.')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sequence_number')
            ->headerActions([])
            ->recordActions([
                Action::make('register_payment')
                    ->label('Registrar pagamento')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->form([
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
                    }),
                Action::make('edit_installment')
                    ->label('Editar parcela')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->form([
                        DatePicker::make('due_date')
                            ->label('Vencimento')
                            ->required(),
                        TextInput::make('notes')
                            ->label('Observações'),
                    ])
                    ->fillForm(fn (AccountPayableInstallment $record): array => [
                        'due_date' => $record->due_date?->format('Y-m-d'),
                        'notes' => $record->notes,
                    ])
                    ->action(function (AccountPayableInstallment $record, array $data): void {
                        $service = app(AccountPayableService::class);
                        $updated = $service->updateInstallment($record, $data);

                        if ($service->hasError() || $updated === null) {
                            Notification::make()
                                ->title($service->getMessageUser() ?: 'Erro ao atualizar parcela.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($service->getMessage() ?: 'Parcela atualizada com sucesso.')
                            ->success()
                            ->send();
                    }),
                Action::make('delete_installment')
                    ->label('Excluir parcela')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (AccountPayableInstallment $record): void {
                        $service = app(AccountPayableService::class);
                        $deleted = $service->deleteInstallment($record);

                        if ($service->hasError() || ! $deleted) {
                            Notification::make()
                                ->title($service->getMessageUser() ?: 'Erro ao excluir parcela.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($service->getMessage() ?: 'Parcela excluída com sucesso.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('Nenhuma parcela gerada')
            ->emptyStateDescription('As parcelas desta conta a pagar aparecerão aqui.');
    }
}
