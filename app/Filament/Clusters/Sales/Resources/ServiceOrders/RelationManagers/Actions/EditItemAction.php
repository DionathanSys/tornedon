<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Schemas\ServiceItemForm;
use App\Models\ServiceOrderItem;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class EditItemAction
{
    use AuthorizesServiceOrderItemActions;

    public static function make(): EditAction
    {
        return EditAction::make()
            ->visible(fn (RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->modalHeading('Editar Item')
            ->schema(ServiceItemForm::make(enforceEffectiveMinSalePrice: true))
            ->fillForm(function (array $data, ServiceOrderItem $record) {
                $data = ServiceItemForm::fillFromRecord(
                    $data,
                    $record->service_id,
                    (float) ($record->service?->min_sale_price ?? 0),
                );
                $data['quantity'] = $record->quantity;
                $data['unit_price'] = $record->unit_price;
                $data['discount_amount'] = $record->discount_amount;
                $data['discount_percentage'] = $record->discount_percentage;
                $data['total_amount'] = $record->total_amount;
                $data['observations'] = $record->observations;

                return $data;
            })
            ->using(function (ServiceOrderItem $record, array $data): ?Model {
                Log::debug('Iniciando atualizacao de item via RelationManager', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'item_id' => $record->id,
                    'data' => $data,
                ]);

                $service = new ServiceOrderItemService;
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return null;
                }

                notify::success(message: $service->getMessageUser());

                return $item;
            });
    }
}
