<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
            ->schema([
                Hidden::make('item.min_sale_price')
                    ->saved(false)
                    ->default(0),
                Select::make('service_id')
                    ->label('Servico')
                    ->searchable()
                    ->relationship('service', 'name', function ($query) {
                        $query->where('services.company_id', Filament::getTenant()->id);
                    })
                    ->required()
                    ->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire) {
                        self::applySelectedService($set, $get, $state, $livewire->getOwnerRecord());
                    }),
                ItemValueGroup::make([
                    'minSalePriceField' => 'item.min_sale_price',
                    'serviceIdField' => 'service_id',
                    'preserveDiscountOnValueChange' => true,
                    'enforceEffectiveMinSalePrice' => true,
                ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $data, ServiceOrderItem $record) {
                $data['service_id'] = $record->service_id;
                $data['item']['min_sale_price'] = (float) ($record->service?->min_sale_price ?? 0);
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
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'item_id' => $record->id,
                    'data' => $data,
                ]);

                $service = new ServiceOrderItemService();
                $item = $service->update($record, $data, Auth::id());

                if ($service->hasError()) {
                    notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
                    return null;
                }

                notify::success(message: $service->getMessageUser());
                return $item;
            });
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
            quantity: (float) ($get('quantity') ?: 1),
            unitPrice: (float) $service->price,
        );

        $set('discount_percentage', number_format((float) $discount['discount_percentage'], 2, ',', '.'));
        $set('discount_amount', number_format((float) $discount['discount_amount'], 2, ',', '.'));

        ItemValueGroup::recalculate($get, $set);
    }
}
