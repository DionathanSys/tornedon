<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Requisitions\Schemas\ItemsForm;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\RequisitionItem\RequisitionItemService;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Log;

final class CreateItemAction
{
    use AuthorizesRequisitionItemActions;
    use ParsesMoneyValues;

    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Peças')
            ->icon(Heroicon::Plus)
            ->size(Size::Small)
            ->visible(fn(RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->modalHeading('Adicionar Item à Requisição')
            ->schema(fn(Schema $schema) => ItemsForm::configure($schema))
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $requisition = $livewire->getOwnerRecord();

                // Extração dos IDs do container 'item'
                $data['requisition_id'] = $requisition->id;

                // Parse numeric values from PT-BR format to float
                $data['quantity']            = self::parseMoneyValue($data['quantity'] ?? 0);
                $data['unit_price']          = self::parseMoneyValue($data['unit_price'] ?? 0);
                $data['discount_amount']     = self::parseMoneyValue($data['discount_amount'] ?? 0);
                $data['discount_percentage'] = self::parseMoneyValue($data['discount_percentage'] ?? 0);

                Log::debug('Iniciando criação de item via RelationManager', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'requisition_id'    => $requisition->id,
                    'data'              => $data,
                ]);

                $service = new RequisitionItemService();
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            })
            ->after(function (ItemsRelationManager $livewire) {
                $livewire->dispatch('refresh-page');
            })
            ->successNotification(null);
    }

}
