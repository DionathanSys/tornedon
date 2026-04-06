<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\Actions;

use App\Models\AccountReceivableInstallment;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class DeleteInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('delete_installment')
            ->label('Excluir parcela')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (AccountReceivableInstallment $record): void {
                $service = app(AccountReceivableService::class);
                $deleted = $service->deleteInstallment($record);

                if ($service->hasError() || ! $deleted) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao excluir parcela.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela excluida com sucesso.')
                    ->success()
                    ->send();
            });
    }
}
