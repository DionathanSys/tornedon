<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use Illuminate\Support\Facades\Log;

final class DeleteItemAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading('Excluir Item')
            ->modalDescription('Tem certeza que deseja excluir este item? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (ServiceOrderItem $record): bool {
                Log::debug('Iniciando exclusão de item via RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                ]);

                $service = new ServiceOrderItemService();
                $result = $service->delete($record);

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return false;
                }

                notify::success(message: $service->getMessageUser());
                return $result;
            });
    }
}
