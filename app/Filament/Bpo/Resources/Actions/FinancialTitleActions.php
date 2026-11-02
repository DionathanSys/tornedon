<?php

namespace App\Filament\Bpo\Resources\Actions;

use App\Filament\Clusters\Financial\Actions\IgnoreFinancialAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class FinancialTitleActions
{
    public static function delete(string $resource): DeleteAction
    {
        return DeleteAction::make()->label('Excluir título')->modalHeading('Excluir lançamento financeiro')->modalCancelActionLabel('Manter título')
            ->modalDescription(fn (Model $record) => 'Documento '.($record->document_number ?: '#'.$record->id).' · '.$record->counterparty_label.' · R$ '.number_format($record->due_amount, 2, ',', '.').'. O título e suas parcelas serão excluídos. Títulos com baixas ou vínculos protegidos exigem tratamento pelo fluxo de origem.')
            ->authorize(fn ($livewire, Model $record) => $livewire->canOperateTitle('delete', $record))
            ->using(function (Model $record) use ($resource): bool {
                Gate::authorize('delete', $record);
                $service = $resource::service();
                $deleted = $service->delete($record);
                if (! $deleted) {
                    Notification::make()->title('Título preservado')->body($service->getMessageUser())->danger()->send();
                }

                return $deleted;
            })->successRedirectUrl($resource::getUrl());
    }

    public static function ignore(bool $restore = false): Action
    {
        return IgnoreFinancialAction::make(restore: $restore)
            ->modalSubmitActionLabel($restore ? 'Voltar a considerar' : 'Ignorar título')->modalCancelActionLabel('Manter título')
            ->visible(fn ($livewire, Model $record) => $livewire->canOperateTitle('change_status_without_movement', $record) && $livewire->canOperateTitle('update', $record))
            ->authorize(fn ($livewire, Model $record) => $livewire->canOperateTitle('change_status_without_movement', $record) && $livewire->canOperateTitle('update', $record));
    }

    public static function deleteBulk(string $resource): DeleteBulkAction
    {
        return DeleteBulkAction::make()->label('Excluir títulos selecionados')->modalCancelActionLabel('Voltar')
            ->authorizeIndividualRecords(false)
            ->authorize(fn ($livewire) => $livewire->canOperateTitle('delete'))
            ->action(function (Collection $records) use ($resource): void {
                DB::transaction(function () use ($records, $resource): void {
                    foreach ($records as $record) {
                        abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
                        Gate::authorize('delete', $record);
                        $service = $resource::service();
                        if (! $service->delete($record)) {
                            throw ValidationException::withMessages(['records' => $service->getMessageUser()]);
                        }
                    }
                });
                Notification::make()->title('Títulos selecionados excluídos')->success()->send();
            })->deselectRecordsAfterCompletion();
    }
}
