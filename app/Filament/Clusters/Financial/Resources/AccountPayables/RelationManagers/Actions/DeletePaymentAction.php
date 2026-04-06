<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Models\AccountPayableInstallmentPayment;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class DeletePaymentAction
{
    public static function make(): Action
    {
        return Action::make('delete_payment')
            ->label('Excluir pagamento')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (AccountPayableInstallmentPayment $record): void {
                $service = app(AccountPayableService::class);
                $deleted = $service->deleteInstallmentPayment($record);

                if ($service->hasError() || ! $deleted) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao excluir pagamento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Pagamento excluido com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
