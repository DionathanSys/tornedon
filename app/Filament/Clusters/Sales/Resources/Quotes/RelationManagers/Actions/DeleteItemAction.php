<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\RelationManagers\Actions;

use App\Models\QuoteItem;
use App\Notification\NotifyService as notify;
use App\Services\QuoteItem\QuoteItemService;
use App\Traits\AuthorizesQuoteItemActions;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Log;

final class DeleteItemAction
{
    use AuthorizesQuoteItemActions;

    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (RelationManager $livewire): bool => self::canDeleteQuoteItem($livewire->getOwnerRecord()))
            ->requiresConfirmation()
            ->modalHeading('Excluir Item')
            ->modalDescription('Tem certeza que deseja excluir este item? Esta ação não pode ser desfeita.')
            ->modalSubmitActionLabel('Sim, excluir')
            ->using(function (QuoteItem $record): bool {
                Log::debug('DeleteItemAction (Quote RelationManager): Iniciando exclusão de item', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                ]);

                $service = new QuoteItemService();
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
