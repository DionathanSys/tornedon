<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
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
            });
    }
}
