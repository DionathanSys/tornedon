<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

final class CreateItemAction
{
    use AuthorizesServiceOrderItemActions;
    use ParsesMoneyValues;

    public static function make(): CreateAction
    {
        return CreateAction::make()
            ->label('Serviço')
            ->icon(Heroicon::Plus)
            ->badge()
            ->visible(fn(RelationManager $livewire): bool => self::canModifyItems($livewire->getOwnerRecord()))
            ->schema([
                ModalSelectService::make('service_id')
                    ->label('Serviço')
                    ->saved(true)
                    ->afterStateUpdated(function (Set $set, callable $get, $state) {
                        $service = (new ServiceService())->find($state);
                        if ($service) {
                            $set('unit_price', number_format($service->price, 2, ',', '.'));
                        } else {
                            $set('unit_price', null);
                        }
                        self::calculateValues($get, $set);
                    }),
                ItemValueGroup::make(),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ])
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $serviceOrder = $livewire->getOwnerRecord();

                $data['service_order_id'] = $serviceOrder->id;

                Log::debug('Iniciando criação de item via RelationManager', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,
                    'service_order_id'  => $serviceOrder->id,
                    'data'              => $data,
                ]);

                $service = new ServiceOrderItemService();
                $item = $service->create($data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            })
            ->successNotification(null);
    }

    protected static function calculateValues(callable $get, Set $set): void
    {
        $quantity = self::parseMoneyValue($get('quantity'));
        $unitPrice = self::parseMoneyValue($get('unit_price'));
        $discountAmount = self::parseMoneyValue($get('discount_amount'));

        // Calcula o subtotal
        $subtotal = $quantity * $unitPrice;
        $set('subtotal', number_format($subtotal, 2, ',', '.'));

        // Calcula o total
        $totalAmount = $subtotal - $discountAmount;
        $set('total_amount', number_format($totalAmount, 2, ',', '.'));

        Log::debug('Valores recalculados', [
            'metodo'        => __METHOD__ . '@' . __LINE__,
            'quantity'      => $quantity,
            'unit_price'    => $unitPrice,
            'discount_amount' => $discountAmount,
            'subtotal'      => $subtotal,
            'total_amount'  => $totalAmount,
        ]);
    }
}
