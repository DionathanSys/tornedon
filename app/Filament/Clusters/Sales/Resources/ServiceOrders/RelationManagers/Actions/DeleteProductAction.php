<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Models\RequisitionItem;
use App\Notification\NotifyService as notify;
use App\Services\RequisitionItem\RequisitionItemService;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Log;

final class DeleteProductAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make('delete-product')
            ->visible(function (RelationManager $livewire, RequisitionItem $record): bool {
                $serviceOrder = $livewire->getOwnerRecord();
                $requisition = $serviceOrder->requisition;

                return ($serviceOrder?->state()?->canEdit() ?? false)
                    && $requisition !== null
                    && $requisition->id === $record->requisition_id
                    && $requisition->state()->canEdit();
            })
            ->requiresConfirmation()
            ->modalHeading('Excluir produto')
            ->modalDescription('Tem certeza que deseja excluir este produto da requisição vinculada?')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (RequisitionItem $record): bool {
                Log::debug('DeleteProductAction: excluindo produto da OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_item_id' => $record->id,
                    'requisition_id' => $record->requisition_id,
                ]);

                $service = app(RequisitionItemService::class);
                $result = $service->forceDelete($record);

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return false;
                }

                notify::success(message: $service->getMessageUser());

                return $result;
            })
            ->after(function (RelationManager $livewire): void {
                $livewire->dispatch('refresh-page');
                $livewire->dispatch('refresh-products');
            })
            ->successNotification(null);
    }
}
