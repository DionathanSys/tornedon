<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Schemas\ServiceItemForm;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateItemAction
{
    use AuthorizesServiceOrderItemActions;

    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Serviço')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->modalHeading('Adicionar Serviço')
            ->schema(ServiceItemForm::make())
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $serviceOrder = $livewire->getOwnerRecord();

                $data['service_order_id'] = $serviceOrder->id;

                Log::debug('Iniciando criacao de item via RelationManager', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'data' => $data,
                ]);

                $service = new ServiceOrderItemService;
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                    return null;
                }

                notify::success(message: $service->getMessageUser());

                return $item;
            })
            ->modalCancelAction(false)
            ->successNotification(null);
    }
}
