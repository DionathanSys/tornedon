<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Log;

final class DeleteItemAction
{
    public static function make(string $name = 'delete'): DeleteAction
    {
        return DeleteAction::make($name)
            ->requiresConfirmation()
            ->modalHeading('Excluir Item')
            ->modalDescription('Tem certeza que deseja excluir este item? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (FiscalDocumentItem $record): bool {
                Log::debug('Excluindo item de nota de entrada (Financial) via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                ]);

                $service = new FiscalDocumentItemService();
                $result = $service->delete($record);

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return false;
                }

                notify::success(message: $service->getMessage());
                return $result;
            })
            ->successNotification(null);
    }
}
