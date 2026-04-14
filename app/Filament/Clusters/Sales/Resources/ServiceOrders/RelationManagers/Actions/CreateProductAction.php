<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\ItemsForm;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\ServiceOrder\ServiceOrderRequisitionService;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class CreateProductAction
{
    use ParsesMoneyValues;

    public static function make(): CreateAction
    {
        return CreateAction::make('create-product')
            ->label('Produto')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn (RelationManager $livewire): bool => $livewire->getOwnerRecord()?->state()?->canEdit() ?? false)
            ->modalHeading('Adicionar produto à OS')
            ->schema(fn (Schema $schema): Schema => ItemsForm::configure($schema))
            ->using(function (array $data, RelationManager $livewire): ?Model {
                /** @var ServiceOrder $serviceOrder */
                $serviceOrder = $livewire->getOwnerRecord();

                $linkedRequisitionService = app(ServiceOrderRequisitionService::class);
                $requisition = $linkedRequisitionService->getOrCreateEditable($serviceOrder, Auth::id());

                if ($requisition === null) {
                    notify::warning(
                        message: $linkedRequisitionService->getMessageUser(),
                        errorCode: $linkedRequisitionService->getErrorCode(),
                    );

                    return null;
                }

                $data['requisition_id'] = $requisition->id;
                $data['quantity'] = self::parseMoneyValue($data['quantity'] ?? 0);
                $data['unit_price'] = self::parseMoneyValue($data['unit_price'] ?? 0);
                $data['discount_amount'] = self::parseMoneyValue($data['discount_amount'] ?? 0);
                $data['discount_percentage'] = self::parseMoneyValue($data['discount_percentage'] ?? 0);

                Log::debug('CreateProductAction: criando produto da OS em requisição vinculada', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'requisition_id' => $requisition->id,
                    'data' => $data,
                ]);

                $service = app(RequisitionItemService::class);
                $item = $service->create($data, Auth::id());

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
