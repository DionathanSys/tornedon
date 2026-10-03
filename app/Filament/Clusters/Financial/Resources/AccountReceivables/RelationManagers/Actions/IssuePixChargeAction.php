<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Enum\Financial\PixChargeStatus;
use App\Enum\Payment\Method as PaymentMethod;
use App\Jobs\RegisterPixChargeJob;
use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\Pix\PixChargeIssuanceService;
use App\Services\Financial\Pix\PixEligibilityService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class IssuePixChargeAction
{
    public static function make(): Action
    {
        return Action::make('issue_pix_charge')
            ->label('Emitir PIX')
            ->icon(Heroicon::QrCode)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Emitir cobrança PIX')
            ->modalDescription('A cobrança PIX será preparada e enviada para registro em segundo plano.')
            ->schema([
                Select::make('financial_account_id')
                    ->label('Conta financeira do PIX')
                    ->options(fn (): array => app(PixEligibilityService::class)
                        ->financialAccountOptions((int) Filament::getTenant()->id))
                    ->default(fn (AccountReceivableInstallment $record): ?int => $record->financial_account_id)
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (AccountReceivableInstallment $record): bool => blank($record->financial_account_id))
                    ->helperText('A conta precisa possuir conexão ativa com capacidade PIX.'),
            ])
            ->visible(function (AccountReceivableInstallment $record): bool {
                if ($record->accountReceivable?->payment_method !== PaymentMethod::PIX) {
                    return false;
                }

                $status = $record->latestPixCharge?->status;

                return $status === null || in_array($status, [
                    PixChargeStatus::REGISTRATION_FAILED,
                    PixChargeStatus::CANCELED,
                    PixChargeStatus::EXPIRED,
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

                $charge = app(PixChargeIssuanceService::class)->preparePixCharge($record);

                if (! $charge) {
                    Notification::make()
                        ->title('Parcela sem elegibilidade para emissão PIX')
                        ->body('Verifique forma de pagamento, entitlement, conta financeira, conexão ativa e dados do pagador.')
                        ->danger()
                        ->send();

                    return;
                }

                if (! in_array($charge->status, [
                    PixChargeStatus::PENDING_REGISTRATION,
                    PixChargeStatus::REGISTRATION_FAILED,
                ], true)) {
                    Notification::make()
                        ->title('A parcela já possui uma cobrança PIX em processamento ou registrada.')
                        ->warning()
                        ->send();

                    return;
                }

                RegisterPixChargeJob::dispatch($charge->id);

                Notification::make()
                    ->title('Cobrança PIX enfileirada para emissão.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-installments');
            });
    }
}
