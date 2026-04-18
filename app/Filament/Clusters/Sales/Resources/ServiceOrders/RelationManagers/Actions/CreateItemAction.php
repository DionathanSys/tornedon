<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectService;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            ->schema([
                Hidden::make('item.min_sale_price')
                    ->saved(false)
                    ->default(0),
                ModalSelectService::make('service_id')
                    ->label('Serviço')
                    ->saved(true)
                    ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire) {
                        self::applySelectedService($set, $get, $state, $livewire->getOwnerRecord());
                    }),
                ItemValueGroup::make([
                    'minSalePriceField'             => 'item.min_sale_price',
                    'serviceIdField'                => 'service_id',
                    'preserveDiscountOnValueChange' => true,
                    'enforceEffectiveMinSalePrice'  => true,
                ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ])
            ->using(function (array $data, RelationManager $livewire): ?Model {
                $serviceOrder = $livewire->getOwnerRecord();

                $data['service_order_id'] = $serviceOrder->id;

                Log::debug('Iniciando criacao de item via RelationManager', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'data' => $data,
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
            ->modalCancelAction(false)
            ->successNotification(null);
    }

    private static function applySelectedService(Set $set, Get $get, mixed $serviceId, ?ServiceOrder $serviceOrder): void
    {
        $service = (new ServiceService())->find((int) $serviceId);

        if (! $service) {
            $set('unit_price', null);
            $set('item.min_sale_price', 0);
            $set('discount_percentage', '0,00');
            $set('discount_amount', '0,00');
            ItemValueGroup::recalculate($get, $set);
            return;
        }

        $set('unit_price', number_format((float) $service->price, 2, ',', '.'));
        $set('item.min_sale_price', (float) ($service->min_sale_price ?? 0));

        $discount = app(ServiceDiscountService::class)->resolveAutomaticDiscount(
            companyId: $serviceOrder?->company_id,
            customerId: $serviceOrder?->customer_id,
            service: $service,
            quantity: 1,
            unitPrice: (float) $service->price,
        );

        $set('discount_percentage', number_format((float) $discount['discount_percentage'], 2, ',', '.'));
        $set('discount_amount', number_format((float) $discount['discount_amount'], 2, ',', '.'));

        ItemValueGroup::recalculate($get, $set);
    }
}
