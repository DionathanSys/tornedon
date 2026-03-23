<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components;

use App\Enum\Quote\Destination;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Services\QuoteItem\QuoteItemResolverService;
use App\Services\ServiceDiscount\ServiceDiscountService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SchemaForm
{
    /**
     * O ponto de entrada do formulario.
     */
    public static function make(string $context = 'create'): array
    {
        return [
            self::getSelectionGroup(),
            self::getInformationGroup(),
            ItemValueGroup::make([
                'preserveDiscountOnValueChange' => true,
                'enforceEffectiveMinSalePrice' => true,
            ]),
            Textarea::make('description')
                ->label('Descricao')
                ->columnSpanFull(),
        ];
    }

    /**
     * Grupo de selecao: contem os campos de busca via ModalTableSelect.
     */
    private static function getSelectionGroup(): Group
    {
        return Group::make()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                ModalSelectProductStock::make(),
                ModalSelectProductForProduction::make(),
                ModalSelectService::make(),
            ]);
    }

    /**
     * Grupo de informacao: exibe os dados de identificacao do item selecionado.
     */
    private static function getInformationGroup(): Group
    {
        return Group::make()
            ->columns(3)
            ->columnSpanFull()
            ->schema([
                Hidden::make('item.real_product_id'),
                Hidden::make('item.real_service_id'),
                Hidden::make('item.min_sale_price')
                    ->saved(false)
                    ->default(0),
                Hidden::make('item.code')
                    ->saved(false),
                Hidden::make('item.name')
                    ->saved(false),

                TextInput::make('item.identification')
                    ->label('Identificação do Item')
                    ->saved(false)
                    ->readOnly()
                    ->dehydrated(false)
                    ->columnSpanFull(),

                TextInput::make('unit_of_measure')
                    ->label('Unidade de Medida')
                    ->disabled()
                    ->saved(true)
                    ->columnSpan(1),

                Select::make('destination')
                    ->label('Finalidade')
                    ->options(Destination::toSelectArray())
                    ->disabled()
                    ->saved(true)
                    ->columnSpan(2),
            ]);
    }

    /**
     * Resolve os dados do item atraves do servico especialista.
     */
    public static function resolveItem(Set $set, Get $get, Destination $type, $id, mixed $livewire = null): void
    {
        if (! $id) {
            return;
        }

        $set('item.product_stock_id', $type === Destination::REQUISITION ? $id : null);
        $set('item.product_id', $type === Destination::ORDER_PRODUCTION ? $id : null);
        $set('item.service_id', $type === Destination::ORDER_SERVICE ? $id : null);

        $service = app(QuoteItemResolverService::class);

        $dto = match ($type) {
            Destination::REQUISITION => $service->resolveForStock($id),
            Destination::ORDER_PRODUCTION => $service->resolveForProduct($id),
            Destination::ORDER_SERVICE => $service->resolveForService($id),
        };

        if (! $dto) {
            return;
        }

        $set('item.real_product_id', $dto->productId);
        $set('item.real_service_id', $dto->serviceId);
        $set('item.code', $dto->code);
        $set('item.name', $dto->name);
        $set('item.identification', $dto->code ? "[{$dto->code}] {$dto->name}" : $dto->name);

        $set('unit_of_measure', $dto->unit);
        $set('destination', $dto->destination->value);
        $set('unit_price', number_format((float) $dto->price, 2, ',', '.'));
        $set('item.min_sale_price', $dto->minSalePrice);

        $set('discount_amount', '0,00');
        $set('discount_percentage', '0,00');

        if ($type === Destination::ORDER_SERVICE && method_exists($livewire, 'getOwnerRecord')) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord) {
                $quantity = self::parseStateNumber($get('quantity'));

                $discount = app(ServiceDiscountService::class)->resolveAutomaticDiscount(
                    companyId: (int) $ownerRecord->company_id,
                    customerId: (int) $ownerRecord->customer_id,
                    service: (int) $dto->serviceId,
                    quantity: $quantity > 0 ? $quantity : 1,
                    unitPrice: (float) $dto->price,
                );

                $set('discount_percentage', number_format((float) $discount['discount_percentage'], 2, ',', '.'));
                $set('discount_amount', number_format((float) $discount['discount_amount'], 2, ',', '.'));
            }
        }

        ItemValueGroup::recalculate($get, $set);
    }

    private static function parseStateNumber(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) str_replace(',', '.', str_replace('.', '', (string) $value));
    }
}
