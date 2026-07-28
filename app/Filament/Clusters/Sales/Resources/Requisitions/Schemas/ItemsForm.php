<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Schemas;

use App\Domain\DTO\Requisition\RequisitionItemDTO;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Tables\ProductsStockTable;
use App\Forms\Components\AutoSubmitModalTableSelect;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductUnitConversionService;
use App\Services\ProductStock\ProductStockService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Selecao do item')
                    ->columnSpanFull()
                    ->schema([
                        Hidden::make('product_code')
                            ->saved(),
                        Hidden::make('product_stock_id')
                            ->required(),
                        Hidden::make('product_id')
                            ->saved()
                            ->required(),
                        Grid::make([
                            'default' => 1,
                            'md' => 5,
                        ])
                            ->schema([
                                TextInput::make('product_code_lookup')
                                    ->label('Cod.')
                                    ->dehydrated(false)
                                    ->autocomplete(false)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::syncProductStockByCode($set, $get, $state))
                                    ->columnSpan(1),
                                Select::make('product_stock_lookup_id')
                                    ->label('Busca simples')
                                    ->dehydrated(false)
                                    ->searchable()
                                    ->native(false)
                                    ->options(fn (): array => self::getProductStockOptions())
                                    ->getSearchResultsUsing(fn (string $search): array => self::getProductStockOptions($search))
                                    ->getOptionLabelUsing(fn ($value): ?string => self::getProductStockOptionLabel($value))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::syncProductStockById($set, $get, $state))
                                    ->columnSpan(3),
                                AutoSubmitModalTableSelect::make('product_stock_lookup_modal')
                                    ->label('Busca avancada')
                                    ->saved(false)
                                    ->getOptionLabelUsing(fn ($value): ?string => self::getProductStockOptionLabel($value))
                                    ->tableConfiguration(ProductsStockTable::class)
                                    ->selectAction(
                                        fn (Action $action) => $action
                                            ->label('Selecionar')
                                            ->modalHeading('Buscar Produto em Estoque')
                                            ->modalSubmitActionLabel('Confirmar selecao')
                                            ->slideOver(false)
                                            ->modalWidth(Width::SevenExtraLarge)
                                    )
                                    ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::syncProductStockById($set, $get, $state))
                                    ->columnSpan(1),
                                TextInput::make('product_name_lookup')
                                    ->label('Item selecionado')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Detalhes do item')
                    ->columnSpanFull()
                    ->schema([
                        FusedGroup::make()
                            ->label('Descricao')
                            ->columns(6)
                            ->columnSpanFull()
                            ->schema([
                                Select::make('unit_of_measure')
                                    ->label('UN')
                                    ->options(fn (Get $get): array => self::availableUnits((int) $get('product_stock_id')))
                                    ->native(false)
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->helperText(fn (Get $get): ?string => self::unitHelperText(
                                        (int) $get('product_stock_id'),
                                        $get('unit_of_measure'),
                                        self::parseNumeric($get('quantity')),
                                    ))
                                    ->saved(true)
                                    ->columnSpan(1),
                                TextInput::make('description')
                                    ->label('Descricao')
                                    ->maxLength(255)
                                    ->columnSpan(5),
                            ]),
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
        if (! $id) {
            self::clearSelectedProductStock($set, $get);

            return;
        }

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
            unit: $product->resolvedSaleUnit(),
            price: (float) ($price ?? 0),  // null = FREE price mode; form starts at 0 for user input
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
        $set('product_stock_lookup_id', $dto->productStockId);
        $set('product_stock_lookup_modal', $dto->productStockId);
        $set('product_stock_id', $dto->productStockId);
        $set('product_id', $dto->productId);
        $set('product_code', $dto->code);
        $set('product_code_lookup', $dto->code);
        $set('product_name_lookup', $dto->code ? "[{$dto->code}] {$dto->name}" : $dto->name);
        $set('description', $dto->name);
        $set('quantity', 1);
        $set('unit_of_measure', $dto->unit instanceof Unit ? $dto->unit->value : $dto->unit);
        $set('unit_price', $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('total_amount', $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('commission_percentage', 0);
        $set('commission_amount', 0);
    }

    public static function hydrateRecordData(array $data, mixed $productId): array
    {
        $productStock = filled($productId)
            ? app(ProductStockService::class)->findByProductId((int) $productId, Filament::getTenant()->id)
            : null;

        $data['product_stock_id'] = $productStock?->id;
        $data['product_stock_lookup_id'] = $productStock?->id;
        $data['product_stock_lookup_modal'] = $productStock?->id;
        $data['product_code_lookup'] = $productStock?->product?->product_code;
        $data['product_name_lookup'] = $productStock?->product
            ? self::formatProductStockLabel($productStock)
            : null;

        return $data;
    }

    private static function syncProductStockByCode(Set $set, Get $get, mixed $productCode): void
    {
        $stock = self::findProductStockByCode($productCode);

        if (! $stock) {
            self::clearSelectedProductStock($set, $get);

            return;
        }

        self::resolveItem($set, $get, $stock->id);
    }

    private static function syncProductStockById(Set $set, Get $get, mixed $productStockId): void
    {
        if (! filled($productStockId)) {
            self::clearSelectedProductStock($set, $get);

            return;
        }

        self::resolveItem($set, $get, (int) $productStockId);
    }

    private static function clearSelectedProductStock(Set $set, Get $get): void
    {
        $set('product_stock_lookup_id', null);
        $set('product_stock_lookup_modal', null);
        $set('product_stock_id', null);
        $set('product_id', null);
        $set('product_code', null);
        $set('product_code_lookup', null);
        $set('product_name_lookup', null);
        $set('description', null);
        $set('unit_of_measure', null);
        $set('quantity', 1);
        $set('unit_price', null);
        $set('total_amount', null);
        $set('commission_percentage', 0);
        $set('commission_amount', 0);
        $set('discount_percentage', '0,00');
        $set('discount_amount', '0,00');

        ItemValueGroup::recalculate($get, $set);
    }

    private static function findProductStockByCode(mixed $productCode): ?ProductStock
    {
        $productCode = trim((string) $productCode);

        if ($productCode === '') {
            return null;
        }

        $normalizedCode = self::normalizeProductCode($productCode);

        return ProductStock::query()
            ->with('product')
            ->where('company_id', Filament::getTenant()->id)
            ->whereHas('product', function ($query) use ($productCode, $normalizedCode): void {
                $query->where('is_invoiceable', true)
                    ->where('is_active', true)
                    ->where(function ($productQuery) use ($productCode, $normalizedCode): void {
                        $productQuery->where('product_code', $productCode);

                        if ($normalizedCode !== $productCode) {
                            $productQuery->orWhere('product_code', $normalizedCode);
                        }
                    });
            })
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM products WHERE products.id = product_stocks.product_id AND products.product_code = ?) THEN 0 ELSE 1 END', [$productCode])
            ->first();
    }

    private static function getProductStockOptions(?string $search = null): array
    {
        $query = ProductStock::query()
            ->with('product')
            ->where('company_id', Filament::getTenant()->id)
            ->whereHas('product', function ($query) {
                $query->where('is_invoiceable', true)
                    ->where('is_active', true);
            });

        if (filled($search)) {
            $query->whereHas('product', function ($productQuery) use ($search): void {
                $productQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy(Product::query()
                ->select('name')
                ->whereColumn('products.id', 'product_stocks.product_id')
                ->limit(1))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductStock $stock): array => [$stock->id => self::formatProductStockLabel($stock)])
            ->all();
    }

    private static function getProductStockOptionLabel(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $stock = ProductStock::query()
            ->with('product')
            ->where('company_id', Filament::getTenant()->id)
            ->find((int) $value);

        return $stock ? self::formatProductStockLabel($stock) : null;
    }

    private static function formatProductStockLabel(ProductStock $stock): string
    {
        $code = $stock->product?->product_code;
        $name = $stock->product?->name ?? 'Produto sem nome';
        $available = number_format((float) $stock->quantity_available, 3, ',', '.');
        $unit = self::baseUnitLabel($stock);

        return trim(($code ? "[{$code}] " : '').$name." | Disp.: {$available} {$unit}");
    }

    private static function normalizeProductCode(string $productCode): string
    {
        return ctype_digit($productCode)
            ? Str::padLeft($productCode, 5, '0')
            : $productCode;
    }

    private static function availableUnits(int $productStockId): array
    {
        if ($productStockId < 1) {
            return [];
        }

        $stock = ProductStock::query()
            ->with('product.alternativeUnitConversions')
            ->find($productStockId);

        if (! $stock?->product) {
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

        if (! $stock?->product) {
            return null;
        }

        $parts = [
            'Disponível: '.number_format((float) $stock->quantity_available, 3, ',', '.').' '.self::baseUnitLabel($stock),
        ];

        if ($unit && $quantity > 0) {
            try {
                $conversion = app(ProductUnitConversionService::class)
                    ->convertToBase($stock->product, (string) $unit, $quantity);

                $parts[] = $conversion->displayRule;
                $parts[] = 'Esta operação consumirá '.number_format($conversion->baseQuantity, 3, ',', '.').' '.$conversion->baseUnit;
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
