<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Tables\ServiceTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Service\ServiceService;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\AuthorizesServiceOrderItemActions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid as Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
                Hidden::make('service_id')
                    ->required(),
                Hidden::make('item.min_sale_price')
                    ->saved(false)
                    ->default(0),
                Grid::make([
                    'default' => 1,
                    'md' => 5,
                ])
                    ->schema([
                        TextInput::make('service_code_lookup')
                            ->label('Cód.')
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire) {
                                self::syncServiceByCode($set, $get, $state, $livewire->getOwnerRecord());
                            })
                            ->autocomplete(false)
                            ->columnSpan(1),
                        Select::make('service_lookup_id')
                            ->label('Busca Simples')
                            ->searchable()
                            ->relationship('service', 'name', function ($query) {
                                $query->where('services.company_id', Filament::getTenant()->id);
                            })
                            ->getOptionLabelFromRecordUsing(fn (Service $record): string => trim("[{$record->service_code}] {$record->name}"))
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire) {
                                self::syncServiceById($set, $get, $state, $livewire->getOwnerRecord());
                            })
                            ->columnSpan(3),
                        AutoSubmitModalTableSelect::make('service_lookup_modal')
                            ->label('Busca avançada')
                            ->saved(false)
                            ->relationship('service', 'service_code')
                            ->tableConfiguration(ServiceTable::class)
                            ->selectAction(
                                fn (Action $action) => $action
                                    ->label('Selecionar')
                                    ->modalHeading('Buscar Serviço')
                                    ->modalSubmitActionLabel('Confirmar seleção')
                                    ->slideOver(false)
                                    ->modalWidth(Width::SevenExtraLarge)
                            )
                            ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire) {
                                self::syncServiceById($set, $get, $state, $livewire->getOwnerRecord());
                            })
                            ->columnSpan(1),
                        TextInput::make('service_name_lookup')
                            ->label('Nome do serviço')
                            ->readOnly()
                            ->columnSpanFull()
                            ->dehydrated(false),
                    ])
                    ->columnSpanFull(),
                ItemValueGroup::make([
                    'minSalePriceField'             => 'item.min_sale_price',
                    'serviceIdField'                => 'service_id',
                    'preserveDiscountOnValueChange' => true,
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

    private static function syncServiceByCode(Set $set, Get $get, mixed $serviceCode, ?ServiceOrder $serviceOrder): void
    {
        $service = self::findServiceByCode($serviceCode);

        self::syncSelectedService($set, $get, $service, $serviceOrder);
    }

    private static function syncServiceById(Set $set, Get $get, mixed $serviceId, ?ServiceOrder $serviceOrder): void
    {
        $service = filled($serviceId)
            ? Service::query()
                ->whereKey((int) $serviceId)
                ->where('company_id', Filament::getTenant()->id)
                ->first()
            : null;

        self::syncSelectedService($set, $get, $service, $serviceOrder);
    }

    private static function syncSelectedService(Set $set, Get $get, ?Service $service, ?ServiceOrder $serviceOrder): void
    {
        $set('service_id', $service?->id);
        $set('service_lookup_id', $service?->id);
        $set('service_lookup_modal', $service?->id);
        $set('service_code_lookup', $service?->service_code);
        $set('service_name_lookup', $service?->name);

        self::applySelectedService($set, $get, $service?->id, $serviceOrder);
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

    private static function findServiceByCode(mixed $serviceCode): ?Service
    {
        $serviceCode = trim((string) $serviceCode);

        if ($serviceCode === '') {
            return null;
        }

        $normalizedCode = self::normalizeServiceCode($serviceCode);

        return Service::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where(function ($query) use ($serviceCode, $normalizedCode) {
                $query->where('service_code', $serviceCode);

                if ($normalizedCode !== $serviceCode) {
                    $query->orWhere('service_code', $normalizedCode);
                }
            })
            ->orderByRaw('CASE WHEN service_code = ? THEN 0 ELSE 1 END', [$serviceCode])
            ->first();
    }

    private static function normalizeServiceCode(string $serviceCode): string
    {
        return ctype_digit($serviceCode)
            ? Str::padLeft($serviceCode, 5, '0')
            : $serviceCode;
    }
}
