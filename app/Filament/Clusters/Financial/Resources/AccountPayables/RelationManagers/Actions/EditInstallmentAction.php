<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\Components\InstallmentEditForm;
use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;

final class EditInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('edit_installment')
            ->label('Editar parcela')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->modalHeading(fn (AccountPayableInstallment $record): string => "Editar parcela {$record->sequence_number}")
            ->modalWidth(Width::FourExtraLarge)
            ->slideOver(false)
            ->modalSubmitActionLabel('Salvar alterações')
            ->schema(InstallmentEditForm::components('payable'))
            ->fillForm(fn (AccountPayableInstallment $record): array => [
                'original_amount' => $record->original_amount,
                'due_date' => $record->due_date?->format('Y-m-d'),
                'competence_date' => $record->competence_date?->format('Y-m-d'),
                'financial_category_id' => $record->financial_category_id,
                'cost_center_id' => $record->cost_center_id,
                'result_center_id' => $record->result_center_id,
                'description' => $record->description,
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
