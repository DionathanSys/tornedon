<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas;

use App\Domain\DTO\FiscalDocument\FiscalDocumentItemSourceDTO;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Product\Origin;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectProduct;
use App\Services\FiscalDocumentItem\FiscalDocumentItemResolverService;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

class SchemaFormItemsNfe
{
    public static function make(string $context = 'create', bool $showTaxesTab = false, bool $disableQuantity = false): array
    {
        return [
            Tabs::make('item_tabs')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Geral')
                        ->schema([
                            ModalSelectProduct::make('product_id')
                                ->label('Produto')
                                ->required()
                                ->afterStateUpdated(function ($state, Set $set) {
                                    if (! $state) {
                                        return;
                                    }

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
                                    TextInput::make('unit_of_measure')
                                        ->label('UN')
                                        ->saved(true)
                                        ->columnSpan(1),
                                    TextInput::make('description')
                                        ->label('Descrição')
                                        ->maxLength(255)
                                        ->columnSpan(5),
                                ]),
                            Callout::make('alert')
                                ->description('Produto não possui vínculo com estoque')
                                ->visible(fn($get) => ! $get('product_stock_id') && $get('product_id'))
                                ->columnSpanFull(),

                            ItemValueGroup::make([
                                'totalAmountField' => 'total_price',
                                'disableQuantity' => $disableQuantity,
                            ]),
                            Textarea::make('additional_information')
                                ->label('Informações Adicionais do Item')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Valores')
                        ->columns(3)
                        ->columnSpanFull()
                        ->schema([
                            Money::make('freight_amount')
                                ->label('Frete'),
                            Money::make('insurance_amount')
                                ->label('Seguro'),
                            Money::make('other_expenses_amount')
                                ->label('Outras'),
                        ]),
                    Tab::make('Impostos')
                        // ->visible($showTaxesTab)
                        ->schema([
                            Section::make('Dados fiscais do item')
                                ->columns(['md' => 6, 'lg' => 12])
                                ->columnSpanFull()
                                ->schema([
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
                                            TextInput::make('cfop_code')
                                                ->label('CFOP')
                                                ->maxLength(4)
                                                ->columnSpan(['md' => 2, 'lg' => 4])
                                                ->required(),
                                            TextInput::make('cest_code')
                                                ->label('CEST')
                                                ->maxLength(9)
                                                ->columnSpan(['md' => 2, 'lg' => 4]),
                                            TextInput::make('barcode')
                                                ->label('Código de Barras')
                                                ->maxLength(60)
                                                ->visible(false)
                                                ->columnSpan(['md' => 3, 'lg' => 6]),
                                        ]),
                                    Grid::make(['md' => 6, 'lg' => 12])
                                        ->columnSpanFull()
                                        ->schema([
                                            TextInput::make('tax_data.imposto.icms.situacao_tributaria')
                                                ->label('CST/CSOSN ICMS')
                                                ->maxLength(3)
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            TextInput::make('tax_data.imposto.icms.modalidade_base_calculo')
                                                ->label('Mod. BC ICMS')
                                                ->maxLength(2)
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.icms.valor_base_calculo')
                                                ->label('BC ICMS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            TextInput::make('tax_data.imposto.icms.aliquota')
                                                ->label('Aliq. ICMS %')
                                                ->numeric()
                                                ->step('0.01')
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.icms.valor')
                                                ->label('Valor ICMS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                        ]),
                                    Grid::make(['md' => 6, 'lg' => 12])
                                        ->columnSpanFull()
                                        ->schema([
                                            TextInput::make('tax_data.imposto.pis.situacao_tributaria')
                                                ->label('CST PIS')
                                                ->maxLength(2)
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.pis.valor_base_calculo')
                                                ->label('BC PIS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            TextInput::make('tax_data.imposto.pis.aliquota')
                                                ->label('Aliq. PIS %')
                                                ->numeric()
                                                ->step('0.01')
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.pis.valor')
                                                ->label('Valor PIS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                        ]),
                                    Grid::make(['md' => 6, 'lg' => 12])
                                        ->columnSpanFull()
                                        ->schema([
                                            TextInput::make('tax_data.imposto.cofins.situacao_tributaria')
                                                ->label('CST COFINS')
                                                ->maxLength(2)
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.cofins.valor_base_calculo')
                                                ->label('BC COFINS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            TextInput::make('tax_data.imposto.cofins.aliquota')
                                                ->label('Aliq. COFINS %')
                                                ->numeric()
                                                ->step('0.01')
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                            Money::make('tax_data.imposto.cofins.valor')
                                                ->label('Valor COFINS')
                                                ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                                                ->columnSpan(['md' => 2, 'lg' => 3]),
                                        ]),
                                ]),
                        ]),
                ]),
        ];
    }

    public static function shouldShowTaxesTab(object $document): bool
    {
        $issuePurpose = $document->issue_purpose?->value ?? $document->issue_purpose ?? null;
        $operationNature = $document->operation_nature?->value ?? $document->operation_nature ?? null;

        return $issuePurpose === IssuePurpose::DEVOLUCAO->value
            || $operationNature === OperationNature::DEVOLUCAO_COMPRA->value;
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

        Log::debug('DTO: ' . json_encode($dto));
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
}
