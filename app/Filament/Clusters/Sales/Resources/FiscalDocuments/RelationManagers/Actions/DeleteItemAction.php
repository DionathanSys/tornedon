<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Log;

final class DeleteItemAction
{
    public static function make(string $name = 'delete'): DeleteAction
    {
        return DeleteAction::make($name)
            ->visible(fn (RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->isNfeSent()
                && ! $livewire->getOwnerRecord()->isNfseSent()
            )
            ->requiresConfirmation()
            ->modalHeading('Excluir Item')
            ->modalDescription('Tem certeza que deseja excluir este item? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (FiscalDocumentItem $record): bool {
                if (filled($record->fiscalDocument?->invoice_id)) {
                    notify::error(message: 'Itens de documentos fiscais originados por fatura não podem ser excluídos manualmente.');

                    return false;
                }

                Log::debug('Excluindo item de nota fiscal via RelationManager', [
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
