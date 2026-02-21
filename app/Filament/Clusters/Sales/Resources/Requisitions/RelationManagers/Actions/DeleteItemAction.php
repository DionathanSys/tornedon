<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions;

use App\Models\RequisitionItem;
use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Log;

final class DeleteItemAction
{
    use AuthorizesServiceOrderItemActions;
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (RelationManager $livewire): bool => self::canDeleteItem($livewire->getOwnerRecord()))
            ->requiresConfirmation()
            ->modalHeading('Excluir Item')
            ->modalDescription('Tem certeza que deseja excluir este item? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (RequisitionItem $record): bool {
                Log::debug('Iniciando exclusão de item via RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                ]);

                $service = new RequisitionItemService();
                $result = $service->forceDelete($record);

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return false;
                }

                notify::success(message: $service->getMessageUser());
                return $result;
            });
    }
}
