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
            ->label('Excluir recebimento')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
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
                    ->title($service->getMessage() ?: 'Recebimento excluido com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
