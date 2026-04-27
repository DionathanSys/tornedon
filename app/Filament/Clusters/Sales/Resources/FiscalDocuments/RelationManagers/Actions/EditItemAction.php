<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\SchemaFormItemsNfe;
use App\Models\FiscalDocumentItem;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use App\Services\ProductStock\ProductStockService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
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
            ->visible(fn(RelationManager $livewire): bool => ! $livewire->getOwnerRecord()->isNfeSent())
            ->schema(fn(RelationManager $livewire): array => SchemaFormItemsNfe::make(
                context: 'edit',
                showTaxesTab: SchemaFormItemsNfe::shouldShowTaxesTab($livewire->getOwnerRecord())
            ))
            ->mutateRecordDataUsing(function (array $data): array {
                $product = app(ProductStockService::class)->findByProductId($data['product_id'], Filament::getTenant()->id);
                $data['product_stock_id'] = $product->stock_id ?? null;

                return $data;
            })
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
