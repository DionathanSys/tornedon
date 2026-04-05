<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class EditInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('edit_installment')
            ->label('Editar parcela')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->schema([
                DatePicker::make('due_date')
                    ->label('Vencimento')
                    ->required(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3),
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
            });
    }
}
