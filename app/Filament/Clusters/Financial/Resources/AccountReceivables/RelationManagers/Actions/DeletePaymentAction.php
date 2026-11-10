<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Models\AccountReceivableInstallmentPayment;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class DeletePaymentAction
{
    public static function make(): Action
    {
        return Action::make('delete_payment')
            ->label(fn (AccountReceivableInstallmentPayment $record): string => $record->installment->accountReceivable->invoiceLinks()->exists() ? 'Estornar recebimento' : 'Excluir recebimento')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (AccountReceivableInstallmentPayment $record): string => $record->installment->accountReceivable->invoiceLinks()->exists()
                ? 'O recebimento e seu vínculo histórico serão preservados. O sistema registrará o estorno financeiro correspondente. Esta ação não envia devolução de dinheiro ao banco/provedor.'
                : 'Excluir este recebimento e tratar seu movimento financeiro, conforme o estado da conciliação.')
            ->action(function (AccountReceivableInstallmentPayment $record): void {
                $service = app(AccountReceivableService::class);
                $deleted = $service->deleteInstallmentPayment($record);

                if ($service->hasError() || ! $deleted) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao excluir recebimento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Recebimento excluído com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
