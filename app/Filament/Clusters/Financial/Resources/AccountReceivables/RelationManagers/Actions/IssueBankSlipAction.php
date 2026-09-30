<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Enum\Financial\BankSlipStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\RegisterBankSlipJob;
use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Banking\BankSlipEligibilityService;
use App\Services\Financial\Banking\BankSlipIssuanceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class IssueBankSlipAction
{
    public static function make(): Action
    {
        return Action::make('issue_bank_slip')
            ->label('Emitir boleto')
            ->icon(Heroicon::BuildingLibrary)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Emitir boleto')
            ->modalDescription('O boleto será preparado e enviado para registro no provider bancário em segundo plano.')
            ->schema([
                Select::make('financial_account_id')
                    ->label('Conta financeira do boleto')
                    ->options(fn (): array => app(BankSlipEligibilityService::class)
                        ->financialAccountOptions((int) Filament::getTenant()->id))
                    ->default(fn (AccountReceivableInstallment $record): ?int => $record->financial_account_id)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (AccountReceivableInstallment $record): bool => $record->latestBankSlip === null
                        && blank($record->financial_account_id))
                    ->visible(fn (AccountReceivableInstallment $record): bool => $record->latestBankSlip === null
                        || in_array($record->latestBankSlip?->status, [
                            BankSlipStatus::CANCELED,
                            BankSlipStatus::PAYMENT_RETURNED,
                        ], true))
                    ->helperText('Selecione a conta bancária que possui provider ativo.'),
            ])
            ->visible(function (AccountReceivableInstallment $record): bool {
                if ($record->accountReceivable?->payment_method !== PaymentMethod::BANK_SLIP) {
                    return false;
                }

                $status = $record->latestBankSlip?->status;

                return $status === null || $record->latestBankSlip?->providerResponseFailed() || in_array($status, [
                    BankSlipStatus::REGISTRATION_FAILED,
                    BankSlipStatus::CANCELED,
                    BankSlipStatus::PAYMENT_RETURNED,
                ], true);
            })
            ->action(function (AccountReceivableInstallment $record, array $data): void {
                $financialAccountId = $data['financial_account_id'] ?? null;

                if (filled($financialAccountId) && (int) $record->financial_account_id !== (int) $financialAccountId) {
                    $accountReceivableService = app(AccountReceivableService::class);
                    $updated = $accountReceivableService->updateInstallment($record, [
                        'financial_account_id' => (int) $financialAccountId,
                    ]);

                    if ($accountReceivableService->hasError() || $updated === null) {
                        Notification::make()
                            ->title('Não foi possível configurar a conta financeira.')
                            ->body($accountReceivableService->getMessageUser())
                            ->danger()
                            ->send();

                        return;
                    }

                    $record = $updated;
                }

                $bankSlip = app(BankSlipIssuanceService::class)->prepareBankSlip(
                    $record,
                    requireAutomaticIssuance: false,
                );

                if (! $bankSlip) {
                    Notification::make()
                        ->title('Parcela sem elegibilidade para emissão')
                        ->body('Verifique forma de pagamento, entitlement, conta financeira, conexão ativa e dados do pagador.')
                        ->danger()
                        ->send();

                    return;
                }

                if (! in_array($bankSlip->status, [
                    BankSlipStatus::PENDING_REGISTRATION,
                    BankSlipStatus::REGISTRATION_FAILED,
                    BankSlipStatus::UPDATE_PENDING,
                ], true)) {
                    Notification::make()
                        ->title('A parcela já possui um boleto em processamento ou registrado.')
                        ->warning()
                        ->send();

                    return;
                }

                RegisterBankSlipJob::dispatch($bankSlip->id);

                Notification::make()
                    ->title('Boleto enfileirado para emissão.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-installments');
            });
    }
}
