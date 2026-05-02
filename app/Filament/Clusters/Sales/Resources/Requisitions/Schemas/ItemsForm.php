<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Schemas;

use App\Domain\DTO\Requisition\RequisitionItemDTO;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProductStock;
use App\Models\ProductStock;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductUnitConversionService;
use App\Services\ProductStock\ProductStockService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Group;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ModalSelectProductStock::make('product_id')
                    ->label('Produto')
                    ->required()
                    ->afterStateUpdated(
                        fn($state, Set $set, Get $get) => self::resolveItem($set, $get, $state)
                    ),
                FusedGroup::make()
                    ->label('Descrição')
                    ->columns(6)
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('product_code')
                            ->saved(),
                        Hidden::make('product_stock_id'),
                        Hidden::make('product_id')
                            ->saved(),
                        Select::make('unit_of_measure')
                            ->label('UN')
                            ->options(fn(Get $get): array => self::availableUnits((int) $get('product_stock_id')))
                            ->native(false)
                            ->required()
                            ->live()
                            ->helperText(fn(Get $get): ?string => self::unitHelperText(
                                (int) $get('product_stock_id'),
                                $get('unit_of_measure'),
                                self::parseNumeric($get('quantity')),
                            ))
                            ->saved(true)
                            ->columnSpan(1),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->maxLength(255)
                            ->columnSpan(5),
                    ]),
                ItemValueGroup::make(),
                Group::make()
                    ->columns(2)
                    ->schema([
                        Money::make('commission_percentage')
                            ->label('Comissão (%)')
                            ->disabled(),
                        Money::make('commission_amount')
                            ->label('Vlr. Comissão')
                            ->disabled(),
                    ]),
                Textarea::make('observations')
                    ->label('Observações')
                    ->columnSpanFull(),
                // TextInput::make('additional_info')
                //     ->label('Informações Adicionais')
                //     ->columnSpanFull(),
            ]);
    }

    /**
     * Resolve os dados do item através do serviço especialista.
     */
    public static function resolveItem(Set $set, Get $get, $id): void
    {
        if (! $id) return;

        $productStockService = app(ProductStockService::class);
        $productSalePriceService = app(ProductSalePriceService::class);

        $productStock = $productStockService->find($id);
        $product = $productStock->product;
        $price = $productSalePriceService->resolve($product, $productStock);

        $dto = new RequisitionItemDTO(
            productStockId: $id,
            productId: $product->id,
            code: $product->product_code,
            name: $product->name,
            unit: $product->unit,
            price: $price,
            minSalePrice: $productStock->min_sale_price ?? 0,
        );

        self::applyDto($set, $dto);

        ItemValueGroup::recalculate($get, $set);
    }

    /**
     * Aplica os valores do DTO nos campos do formulário.
     */
    private static function applyDto(Set $set, RequisitionItemDTO $dto): void
    {
        $set('product_stock_id', $dto->productStockId);
        $set('product_id',       $dto->productId);
        $set('product_code',     $dto->code);
        $set('description',      $dto->name);
        $set('quantity',         1);
        $set('unit_of_measure',  $dto->unit);
        $set('unit_price',       $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('total_price',      $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('commission_percentage', 0);
        $set('commission_amount',     0);
    }

    private static function availableUnits(int $productStockId): array
    {
        if ($productStockId < 1) {
            return [];
        }

        $stock = ProductStock::query()
            ->with('product.alternativeUnitConversions')
            ->find($productStockId);

        if (!$stock?->product) {
            return [];
        }

        $units = app(ProductUnitConversionService::class)->getAvailableUnits($stock->product);
        $labels = Unit::toSelectArray();

        $options = [];

        foreach ($units as $unit) {
            $options[$unit] = $labels[$unit] ?? $unit;
        }

        return $options;
    }

    private static function unitHelperText(int $productStockId, mixed $unit, float $quantity): ?string
    {
        if ($productStockId < 1) {
            return null;
        }

        $stock = ProductStock::query()
            ->with('product.alternativeUnitConversions')
            ->find($productStockId);

        if (!$stock?->product) {
            return null;
        }

        $parts = [
            'Disponível: ' . number_format((float) $stock->quantity_available, 3, ',', '.') . ' ' . self::baseUnitLabel($stock),
        ];

        if ($unit && $quantity > 0) {
            try {
                $conversion = app(ProductUnitConversionService::class)
                    ->convertToBase($stock->product, (string) $unit, $quantity);

                $parts[] = $conversion->displayRule;
                $parts[] = 'Esta operação consumirá ' . number_format($conversion->baseQuantity, 3, ',', '.') . ' ' . $conversion->baseUnit;
            } catch (\Throwable) {
                // A validação trata unidade inválida; o helper apenas omite a conversão.
            }
        }

        return implode(' | ', $parts);
    }

    private static function baseUnitLabel(ProductStock $stock): string
    {
        return $stock->product?->unit?->value
            ?? (string) ($stock->product?->unit ?? Unit::UN->value);
    }

    private static function parseNumeric(mixed $value): float
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
