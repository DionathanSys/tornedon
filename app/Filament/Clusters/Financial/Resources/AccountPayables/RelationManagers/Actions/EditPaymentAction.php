<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Models\AccountPayableInstallmentPayment;
use App\Models\FinancialAccount;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class EditPaymentAction
{
    public static function make(): Action
    {
        return Action::make('edit_payment')
            ->label('Editar pagamento')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->schema([
                DatePicker::make('payment_date')
                    ->label('Data do pagamento')
                    ->required(),
                Money::make('amount')
                    ->label('Valor pago')
                    ->required(),
                Money::make('interest_amount')
                    ->label('Juros'),
                Money::make('fine_amount')
                    ->label('Multa'),
                Money::make('discount_amount')
                    ->label('Desconto'),
                TextInput::make('bank_account_id')
                    ->label('Conta bancaria (ID)')
                    ->numeric()
                    ->visible(false),
                Select::make('financial_account_id')
                    ->label('Conta Financeira')
                    ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(),
                Textarea::make('description')
                    ->label('Descricao do Movimento')
                    ->rows(2)
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Observacoes')
                    ->rows(3),
            ])
            ->fillForm(fn (AccountPayableInstallmentPayment $record): array => [
                'payment_date' => $record->payment_date?->format('Y-m-d'),
                'amount' => $record->amount,
                'interest_amount' => $record->interest_amount,
                'fine_amount' => $record->fine_amount,
                'discount_amount' => $record->discount_amount,
                'bank_account_id' => $record->bank_account_id,
                'financial_account_id' => $record->financial_account_id,
                'description' => $record->description,
                'notes' => $record->notes,
            ])
            ->action(function (AccountPayableInstallmentPayment $record, array $data): void {
                $service = app(AccountPayableService::class);
                $updated = $service->updateInstallmentPayment($record, $data);

                if ($service->hasError() || $updated === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao atualizar pagamento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Pagamento atualizado com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
