<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfe;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditItemAction
{
    public static function make(): EditAction
    {
        return EditAction::make()
            ->label('Editar')
            ->visible(fn (RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->nfeSent())
            ->schema(SchemaFormItemsNfe::make('edit'))
            ->using(function (FiscalDocumentItem $record, array $data): ?Model {
                Log::debug('Atualizando item de nota fiscal via RelationManager', [
                    'metodo'  => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data'    => $data,
                ]);

                $service = new FiscalDocumentItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessage(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessage());
                return $item;
            })
            ->successNotification(null);
    }
}
