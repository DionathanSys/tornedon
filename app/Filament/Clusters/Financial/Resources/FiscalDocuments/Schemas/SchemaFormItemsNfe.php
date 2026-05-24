<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas;

use App\Domain\DTO\FiscalDocument\FiscalDocumentItemSourceDTO;
use App\Enum\Product\Unit;
use App\Enum\Product\Origin;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProduct;
use App\Models\Product;
use App\Services\Product\ProductUnitConversionService;
use App\Services\FiscalDocumentItem\FiscalDocumentItemResolverService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SchemaFormItemsNfe
{
    public static function make(string $context = 'create'): array
    {
        return [
            ModalSelectProduct::make('product_id')
                ->label('Produto')
                ->required()
                ->afterStateUpdated(function ($state, Set $set) {
                    if (! $state) return;

                    self::resolveItem($set, $state);
                }),

            FusedGroup::make()
                ->label('Descrição')
                ->columns(6)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('product_code')
                        ->saved(),
                    Hidden::make('product_stock_id')
                        ->live(),
                    Select::make('unit_of_measure')
                        ->label('UN')
                        ->options(fn(Get $get): array => self::availableUnits((int) $get('product_id')))
                        ->native(false)
                        ->required()
                        ->live()
                        ->helperText(fn(Get $get): ?string => self::conversionInfo(
                            (int) $get('product_id'),
                            $get('unit_of_measure'),
                            self::parseNumber($get('quantity')),
                        ))
                        ->saved(true)
                        ->placeholder('UN...')
                        ->columnSpan(1),
                    TextInput::make('description')
                        ->label('Descrição')
                        ->maxLength(255)
                        ->columnSpan(5),
                ]),
            // Callout::make('alert')
            //     ->description('Produto não possui vínculo com estoque')
            //     ->visible(fn($get) => !$get('product_stock_id') && $get('product_id'))
            //     ->columnSpanFull(),

            ItemValueGroup::make([
                'totalAmountField' => 'total_price',
            ]),

            // Códigos fiscais
            Group::make()
                ->columns(['md' => 6, 'lg' => 12])
                ->columnSpanFull()
                ->schema([
                    Select::make('product_origin')
                        ->label('Origem')
                        ->options(Origin::toSelectArray())  
                        ->required()
                        ->columnSpan(['md' => 4, 'lg' => 8])
                        ->native(false),
                    TextInput::make('ncm_code')
                        ->label('NCM')
                        ->maxLength(8)
                        ->columnSpan(['md' => 2, 'lg' => 4]),
                    TextInput::make('cest_code')
                        ->label('CEST')
                        ->maxLength(9)
                        ->visible(false)
                        ->columnSpan(['md' => 3, 'lg' => 6]),
                    TextInput::make('cfop_code')
                        ->label('CFOP')
                        ->visible(false)
                        ->maxLength(4)
                        ->columnSpan(['md' => 3, 'lg' => 6]),
                    TextInput::make('barcode')
                        ->label('Código de Barras')
                        ->maxLength(60)
                        ->visible(false)
                        ->columnSpan(['md' => 3, 'lg' => 6]),
                ]),

            Section::make('Outros Valores')
                ->columns(3)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    Money::make('freight_amount')
                        ->label('Frete'),
                    Money::make('insurance_amount')
                        ->label('Seguro'),
                    Money::make('other_expenses_amount')
                        ->label('Outras'),
                ]),

            Textarea::make('additional_information')
                ->label('Informações Adicionais do Item')
                ->rows(2)
                ->maxLength(500)
                ->columnSpanFull(),
        ];
    }

    /**
     * Resolve os dados do produto via serviço especialista e preenche o formulário.
     */
    public static function resolveItem(Set $set, int $productId): void
    {
        $dto = app(FiscalDocumentItemResolverService::class)
            ->resolveForProduct($productId);

        if (! $dto) {
            return;
        }

        Log::debug('DTO (Financial/NF-e entrada): ' . json_encode($dto));
        self::applyDto($set, $dto);
    }

    /**
     * Aplica os valores do DTO nos campos do formulário.
     */
    private static function applyDto(Set $set, FiscalDocumentItemSourceDTO $dto): void
    {
        $set('product_stock_id', $dto->productStockId);
        $set('product_code',     $dto->productCode);
        $set('description',      $dto->name);
        $set('unit_of_measure',  $dto->unit);
        $set('unit_price',       $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('total_price',      $dto->price ? number_format($dto->price, 2, ',', '.') : null);
        $set('product_origin',   $dto->productOrigin);
        $set('ncm_code',         $dto->ncmCode);
        $set('cest_code',        $dto->cestCode);
        $set('barcode',          $dto->barcode);
    }

    private static function availableUnits(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (! $product) {
            return [];
        }

        $units = app(ProductUnitConversionService::class)->getAvailableUnits($product);
        $labels = Unit::toSelectArray();
        $options = [];

        foreach ($units as $unit) {
            $options[$unit] = $labels[$unit] ?? $unit;
        }

        return $options;
    }

    private static function conversionInfo(int $productId, mixed $unit, float $quantity): ?string
    {
        if ($productId < 1 || ! $unit || $quantity <= 0) {
            return null;
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (! $product) {
            return null;
        }

        try {
            $conversion = app(ProductUnitConversionService::class)
                ->convertToBase($product, (string) $unit, $quantity);

            return sprintf(
                '%s | Tributável/estoque: %s %s',
                $conversion->displayRule,
                number_format($conversion->baseQuantity, 4, ',', '.'),
                $conversion->baseUnit,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parseNumber(mixed $value): float
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
