<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Schemas;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Tables\ServiceTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Service\ServiceService;
use App\Services\ServiceDiscount\ServiceDiscountService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class ServiceItemForm
{
    public static function make(bool $enforceEffectiveMinSalePrice = false): array
    {
        return [
            Section::make('Selecao do servico')
                ->columnSpanFull()
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
                                ->label('Cod.')
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire): void {
                                    self::syncServiceByCode($set, $get, $state, $livewire->getOwnerRecord());
                                })
                                ->autocomplete(false)
                                ->columnSpan(1),
                            Select::make('service_lookup_id')
                                ->label('Busca simples')
                                ->searchable()
                                ->relationship('service', 'name', function ($query) {
                                    $query->where('services.company_id', Filament::getTenant()->id);
                                })
                                ->getOptionLabelFromRecordUsing(fn (Service $record): string => trim("[{$record->service_code}] {$record->name}"))
                                ->dehydrated(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire): void {
                                    self::syncServiceById($set, $get, $state, $livewire->getOwnerRecord());
                                })
                                ->columnSpan(3),
                            AutoSubmitModalTableSelect::make('service_lookup_modal')
                                ->label('Busca avancada')
                                ->saved(false)
                                ->relationship('service', 'service_code')
                                ->tableConfiguration(ServiceTable::class)
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label('Selecionar')
                                        ->modalHeading('Buscar Servico')
                                        ->modalSubmitActionLabel('Confirmar selecao')
                                        ->slideOver(false)
                                        ->modalWidth(Width::SevenExtraLarge)
                                )
                                ->afterStateUpdated(function (Set $set, Get $get, $state, RelationManager $livewire): void {
                                    self::syncServiceById($set, $get, $state, $livewire->getOwnerRecord());
                                })
                                ->columnSpan(1),
                            TextInput::make('service_name_lookup')
                                ->label('Servico selecionado')
                                ->readOnly()
                                ->columnSpanFull()
                                ->dehydrated(false),
                        ])
                        ->columnSpanFull(),
                ]),
            Section::make('Valores')
                ->columnSpanFull()
                ->schema([
                    ItemValueGroup::make([
                        'minSalePriceField' => 'item.min_sale_price',
                        'serviceIdField' => 'service_id',
                        'preserveDiscountOnValueChange' => true,
                        'enforceEffectiveMinSalePrice' => $enforceEffectiveMinSalePrice,
                    ]),
                ]),
            Textarea::make('observations')
                ->label('Observacoes')
                ->columnSpanFull(),
        ];
    }

    public static function fillFromRecord(array $data, int|string|null $serviceId, float $minSalePrice = 0): array
    {
        $data['service_id'] = $serviceId;
        $data['service_lookup_id'] = $serviceId;
        $data['service_lookup_modal'] = $serviceId;
        $data['item']['min_sale_price'] = $minSalePrice;

        if (filled($serviceId)) {
            $service = Service::query()
                ->whereKey((int) $serviceId)
                ->where('company_id', Filament::getTenant()->id)
                ->first();

            $data['service_code_lookup'] = $service?->service_code;
            $data['service_name_lookup'] = $service?->name;
        }

        return $data;
    }

    public static function syncServiceByCode(Set $set, Get $get, mixed $serviceCode, ?ServiceOrder $serviceOrder): void
    {
        $service = self::findServiceByCode($serviceCode);

        self::syncSelectedService($set, $get, $service, $serviceOrder);
    }

    public static function syncServiceById(Set $set, Get $get, mixed $serviceId, ?ServiceOrder $serviceOrder): void
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
        $service = (new ServiceService)->find((int) $serviceId);

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
            quantity: max(1, (float) ($get('quantity') ?: 1)),
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
