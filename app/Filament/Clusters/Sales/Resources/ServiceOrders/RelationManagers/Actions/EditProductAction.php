<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\ItemsForm;
use App\Models\RequisitionItem;
use App\Notification\NotifyService as notify;
use App\Services\Product\ProductSalePriceService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditProductAction
{
    use ParsesMoneyValues;

    public static function make(): EditAction
    {
        return EditAction::make('edit-product')
            ->label('Editar')
            ->visible(function (RelationManager $livewire, RequisitionItem $record): bool {
                $serviceOrder = $livewire->getOwnerRecord();
                $requisition = $serviceOrder->requisition;

                return ($serviceOrder?->state()?->canEdit() ?? false)
                    && $requisition !== null
                    && $requisition->id === $record->requisition_id
                    && $requisition->state()->canEdit();
            })
            ->mutateRecordDataUsing(function (array $data, RequisitionItem $record): array {
                $data['_min_sale_price'] = $record->product_id
                    ? (new ProductSalePriceService())->getMinSalePriceById($record->product_id)
                    : 0;
                $data['description'] = $record->product?->name;

                return $data;
            })
            ->schema(fn (Schema $schema): Schema => ItemsForm::configure($schema))
            ->using(function (RequisitionItem $record, array $data): ?Model {
                Log::debug('EditProductAction: atualizando produto da OS', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_item_id' => $record->id,
                    'requisition_id' => $record->requisition_id,
                    'data' => $data,
                ]);

                $service = app(RequisitionItemService::class);
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());

                return $item;
            })
            ->after(function (RelationManager $livewire): void {
                $livewire->dispatch('refresh-page');
                $livewire->dispatch('refresh-products');
            })
            ->successNotification(null);
    }
}
