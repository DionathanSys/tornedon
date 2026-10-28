<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages;

use App\Enum\Product\Origin;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions\SefazDistributionDocumentRecordActions;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\SefazDistributionDocumentResource;
use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use App\Services\Product\ProductService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ViewSefazDistributionDocument extends ViewRecord
{
    protected static string $resource = SefazDistributionDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ...SefazDistributionDocumentRecordActions::make(),
                Action::make('openProductCreatePage')
                    ->label('Cad. Produto')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small)
                    ->url(fn (): string => ProductResource::getUrl('create', [
                        'tenant' => Filament::getTenant(),
                    ]))
                    ->openUrlInNewTab(),
                Action::make('createProductFromXml')
                    ->label('Cad. Produto XML')
                    ->size(Size::Small)
                    ->icon(Heroicon::Plus)
                    ->modalWidth('4xl')
                    ->extraModalFooterActions(fn (Action $action): array => [
                        $action->makeModalSubmitAction('createAnother', arguments: ['another' => true])
                            ->label('Criar e próximo')
                            ->color('secondary'),
                    ])
                    ->modalSubmitAction(fn (Action $action) => $action->label('Criar'))
                    ->visible(fn (): bool => ! empty(($this->record->items_json ?? [])))
                    ->schema(fn (Schema $schema) => $schema
                        ->columns(4)
                        ->components([
                            Select::make('item_index')
                                ->label('Item do XML')
                                ->required()
                                ->native(false)
                                ->columnSpanFull()
                                ->options(function (): array {
                                    $items = collect($this->record->items_json ?? [])->values();

                                    return $items
                                        ->mapWithKeys(function (array $item, int $index): array {
                                            $line = $item['line'] ?? ($index + 1);
                                            $code = trim((string) ($item['product_code'] ?? ''));
                                            $description = trim((string) ($item['description'] ?? 'Sem descrição'));
                                            $label = 'Linha '.$line.' - '.($code !== '' ? '['.$code.'] ' : '').$description;

                                            return [$index => mb_substr($label, 0, 180)];
                                        })
                                        ->all();
                                })
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    foreach ($this->getCreateProductFormDataFromItemIndex((int) $state) as $field => $value) {
                                        $set($field, $value);
                                    }
                                }),
                            TextInput::make('name')
                                ->label('Nome')
                                ->required()
                                ->columnSpanFull()
                                ->maxLength(255),
                            Textarea::make('description')
                                ->label('Descrição')
                                ->rows(2)
                                ->columnSpanFull()
                                ->maxLength(500),
                            Select::make('category_id')
                                ->label('Categoria')
                                ->native(false)
                                ->searchable()
                                ->preload()
                                ->columnSpan(2)
                                ->options(fn (): array => Category::query()
                                    ->where('company_id', $this->record->company_id)
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all()),
                            Select::make('unit')
                                ->label('Unidade')
                                ->required()
                                ->columnSpan(1)
                                ->native(false)
                                ->options(Unit::toSelectArray())
                                ->default(Unit::UN->value),
                            TextInput::make('xml_unit')
                                ->label('Unidade no XML')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(1),
                            TextInput::make('xml_ncm')
                                ->label('NCM do XML')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(1),
                            TextInput::make('xml_cest')
                                ->label('CEST do XML')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpan(1),
                            Select::make('xml_product_origin')
                                ->label('Origem do produto (padrão)')
                                ->options(Origin::toSelectArray())
                                ->native(false)
                                ->columnSpan(1),
                            TextInput::make('manufacturer_code')
                                ->label('Código do fornecedor (XML)')
                                ->maxLength(100)
                                ->columnStart(1)
                                ->columnSpan(2),
                            TextInput::make('barcode')
                                ->label('EAN')
                                ->maxLength(60)
                                ->columnSpan(2),
                            TextInput::make('profit_margin')
                                ->label('Margem de lucro (%)')
                                ->numeric()
                                ->step('0.01')
                                ->columnStart(1)
                                ->columnSpan(1)
                                ->default(0),
                            Money::make('min_sale_price')
                                ->label('Preço mínimo de venda')
                                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                ->columnSpan(1),
                            Select::make('origin_sale_price')
                                ->label('Origem do preço de venda')
                                ->default(OriginSalePrice::CALCULATED_II->value)
                                ->options(OriginSalePrice::toSelectArray())
                                ->columnSpan(2)
                                ->live(),
                            Money::make('sale_price_value')
                                ->label('Valor de venda fixo')
                                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                                ->columnSpan(1)
                                ->visible(fn (callable $get): bool => (string) $get('origin_sale_price') === OriginSalePrice::FIXED->value),
                            Toggle::make('has_stock_control')
                                ->label('Controla estoque?')
                                ->columnSpan(1)
                                ->columnStart(1)
                                ->inline(false)
                                ->default(true),
                            Toggle::make('is_active')
                                ->label('Ativo')
                                ->columnSpan(1)
                                ->inline(false)
                                ->default(true),
                        ]))
                    ->action(function (array $data, array $arguments, Action $action): void {
                        $items = collect($this->record->items_json ?? [])->values();
                        $index = (int) ($data['item_index'] ?? -1);
                        $item = $items->get($index);

                        if (! is_array($item)) {
                            Notification::make()
                                ->title('Item inválido')
                                ->danger()
                                ->body('Selecione um item válido do XML para continuar.')
                                ->send();

                            return;
                        }

                        $productService = app(ProductService::class);

                        $productPayload = [
                            'name' => (string) ($data['name'] ?? ''),
                            'description' => (string) ($data['description'] ?? ''),
                            'category_id' => $data['category_id'] ? (int) $data['category_id'] : null,
                            'company_id' => (int) $this->record->company_id,
                            'unit' => (string) ($data['unit'] ?? Unit::UN->value),
                            'manufacturer_code' => (string) ($data['manufacturer_code'] ?? ''),
                            'barcode' => (string) ($data['barcode'] ?? ''),
                            'profit_margin' => (float) ($data['profit_margin'] ?? 0),
                            'min_sale_price' => (float) ($data['min_sale_price'] ?? 0),
                            'origin_sale_price' => (string) ($data['origin_sale_price'] ?? OriginSalePrice::CALCULATED_II->value),
                            'sale_price_value' => (float) ($data['sale_price_value'] ?? 0),
                            'has_stock_control' => (bool) ($data['has_stock_control'] ?? false),
                            'is_active' => (bool) ($data['is_active'] ?? true),
                            'tax' => array_filter([
                                'product_origin' => (string) ($data['xml_product_origin'] ?? Origin::NACIONAL->value),
                                'ncm_code' => (string) ($item['ncm'] ?? $item['ncm_code'] ?? ''),
                                'cest_code' => (string) ($item['cest'] ?? $item['cest_code'] ?? ''),
                            ], fn ($value): bool => $value !== ''),
                            'external_reference_codes' => array_filter([
                                'xml_product_code' => (string) ($item['product_code'] ?? ''),
                                'xml_ncm' => (string) ($item['ncm'] ?? ''),
                                'xml_cfop' => (string) ($item['cfop'] ?? ''),
                            ], fn ($value): bool => $value !== ''),
                        ];

                        $product = $productService->create($productPayload, (int) Auth::id());

                        if (! $product instanceof Product) {
                            Notification::make()
                                ->title('Falha ao cadastrar produto')
                                ->danger()
                                ->body($productService->getMessage() ?: 'Não foi possível cadastrar o produto com os dados informados.')
                                ->send();

                            return;
                        }

                        $updatedItems = $items
                            ->map(function (array $currentItem, int $currentIndex) use ($index, $product): array {
                                if ($currentIndex !== $index) {
                                    return $currentItem;
                                }

                                $currentItem['product_id'] = $product->id;
                                $currentItem['product_name'] = $product->name;
                                $currentItem['product_unit'] = $product->unit?->value ?? (string) $product->unit;

                                return $currentItem;
                            })
                            ->all();

                        app(SefazDistributionDocumentService::class)->updateItemMappings(
                            $this->record->fresh(),
                            $updatedItems,
                            Auth::id(),
                        );

                        Notification::make()
                            ->title('Produto cadastrado e vinculado')
                            ->success()
                            ->body('Produto '.$product->name.' vinculado ao item selecionado do DF-e.')
                            ->send();

                        $this->record = $this->record->fresh();
                        $this->dispatch('sefaz-distribution-document-items-refresh');

                        if ($arguments['another'] ?? false) {
                            $nextIndex = $index + 1;

                            if ($items->has($nextIndex)) {
                                $action->fillForm($this->getCreateProductFormDataFromItemIndex($nextIndex));
                                $action->halt();
                            }
                        }
                    }),
                Action::make('back')
                    ->label('Voltar')
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->size(Size::Small)
                    ->url(SefazDistributionDocumentResource::getUrl()),
            ])->label('Ações')->button(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCreateProductFormDataFromItemIndex(int $itemIndex): array
    {
        $item = collect($this->record->items_json ?? [])->values()->get($itemIndex);

        if (! is_array($item)) {
            return [];
        }

        $unit = mb_strtoupper(trim((string) ($item['unit'] ?? 'UN')));

        return [
            'item_index' => $itemIndex,
            'name' => (string) ($item['description'] ?? ''),
            'description' => '',
            'manufacturer_code' => (string) ($item['product_code'] ?? ''),
            'barcode' => (string) ($item['ean'] ?? ''),
            'xml_ncm' => (string) ($item['ncm'] ?? ''),
            'xml_cest' => (string) ($item['cest'] ?? $item['cest_code'] ?? ''),
            'xml_product_origin' => Origin::NACIONAL->value,
            'xml_unit' => (string) ($item['unit'] ?? ''),
            'xml_quantity' => (string) ($item['quantity'] ?? ''),
            'xml_unit_value' => (string) ($item['unit_value'] ?? ''),
            'xml_total_value' => (string) ($item['total_value'] ?? ''),
            'unit' => Unit::tryFrom($unit)?->value ?? Unit::UN->value,
        ];
    }
}
