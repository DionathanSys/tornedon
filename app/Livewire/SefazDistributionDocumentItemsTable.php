<?php

namespace App\Livewire;

use App\Enum\Product\Unit;
use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use App\Services\Product\ProductUnitConversionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class SefazDistributionDocumentItemsTable extends TableComponent
{
    public int $documentId;

    /**
     * @var array<int, string>
     */
    public array $productOptions = [];

    public function mount(int $documentId): void
    {
        $this->refreshProductOptions();
    }

    #[On('sefaz-distribution-document-items-refresh')]
    public function refreshItemsTable(): void
    {
        $this->refreshProductOptions();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->getTableRecords())
            ->paginated(false)
            ->columns([
                TextColumn::make('line')
                    ->label('Linha')
                    ->alignCenter(),
                TextColumn::make('product_code')
                    ->label('Cód. XML')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descrição XML')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Qtd.')
                    ->placeholder('-'),
                TextColumn::make('xml_unit')
                    ->label('Unidade NF')
                    ->state(fn (array $record): string => $this->formatUnitLabel($record['xml_unit'] ?? null))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('product_unit')
                    ->label('Unidade Interna')
                    ->state(fn (array $record): string => $this->formatUnitLabel($record['product_unit'] ?? null))
                    ->badge()
                    ->color(fn (array $record): string => $record['product_unit'] ? 'info' : 'gray'),
                TextColumn::make('product_name')
                    ->label('Produto vinculado')
                    ->state(fn (array $record): string => $record['product_name'] ?: 'Não vinculado')
                    ->url(fn (array $record): ?string => $record['product_id']
                        ? ProductResource::getUrl('edit', ['record' => $record['product_id'], 'tenant' => $this->getDocument()->company_id])
                        : null)
                    ->openUrlInNewTab()
                    ->badge()
                    ->color(fn (array $record): string => $record['product_id'] ? 'success' : 'gray')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('link')
                    ->iconButton()
                    ->tooltip(fn (array $record): string => $record['product_id'] ? 'Alterar vínculo' : 'Vincular')
                    ->icon('heroicon-o-link')
                    ->schema([
                        Select::make('product_id')
                            ->label('Produto interno')
                            ->options(fn (): array => $this->productOptions)
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $set('product_unit', $this->defaultUnitForProduct(is_numeric($state) ? (int) $state : null));
                            })
                            ->required(),
                        Select::make('product_unit')
                            ->label('Unidade para este fornecedor')
                            ->options(fn (Get $get): array => $this->unitOptionsForProduct(is_numeric($get('product_id')) ? (int) $get('product_id') : null))
                            ->native(false)
                            ->required(),
                    ])
                    ->fillForm(fn (array $record): array => [
                        'product_id' => $record['product_id'],
                        'product_unit' => $record['product_unit'] ?: $this->defaultUnitForProduct(isset($record['product_id']) ? (int) $record['product_id'] : null),
                    ])
                    ->action(function (array $data, array $record): void {
                        $this->updateItemMapping(
                            itemIndex: (int) $record['item_index'],
                            productId: (int) $data['product_id'],
                            productUnit: (string) $data['product_unit'],
                        );
                    }),
                Action::make('unlink')
                    ->iconButton()
                    ->tooltip('Remover vínculo')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => filled($record['product_id']))
                    ->action(function (array $record): void {
                        $this->updateItemMapping(
                            itemIndex: (int) $record['item_index'],
                            productId: null,
                            productUnit: null,
                        );
                    }),
            ]);
    }

    public function render(): View
    {
        return view('livewire.sefaz-distribution-document-items-table');
    }

    protected function getDocument(): SefazDistributionDocument
    {
        return SefazDistributionDocument::query()->findOrFail($this->documentId);
    }

    protected function refreshProductOptions(): void
    {
        $document = $this->getDocument();

        $this->productOptions = Product::query()
            ->where('company_id', $document->company_id)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => trim(($product->product_code ? "[{$product->product_code}] " : '').$product->name),
            ])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getTableRecords(): Collection
    {
        return collect($this->getDocument()->items_json ?? [])
            ->values()
            ->mapWithKeys(function (array $item, int $index): array {
                $recordKey = (string) $index;

                return [
                    $recordKey => [
                        '__key' => $recordKey,
                        'item_index' => $index,
                        'line' => $item['line'] ?? $index + 1,
                        'product_code' => $item['product_code'] ?? null,
                        'description' => $item['description'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'xml_unit' => $item['taxable_unit'] ?? $item['unit_of_measure'] ?? $item['unit'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'product_unit' => $item['product_unit'] ?? null,
                        'product_name' => $item['product_name'] ?? null,
                    ],
                ];
            });
    }

    protected function updateItemMapping(int $itemIndex, ?int $productId, ?string $productUnit): void
    {
        $document = $this->getDocument();
        $product = $productId
            ? Product::query()
                ->whereKey($productId)
                ->where('company_id', $document->company_id)
                ->first()
            : null;

        if ($productId !== null && ! $product) {
            Notification::make()
                ->title('Produto inválido')
                ->danger()
                ->body('Selecione um produto da empresa atual para continuar.')
                ->send();

            return;
        }

        if ($product && ! $this->isAllowedUnitForProduct($product, $productUnit)) {
            Notification::make()
                ->title('Unidade inválida')
                ->danger()
                ->body('Selecione a unidade padrão do produto ou uma unidade alternativa cadastrada nele.')
                ->send();

            return;
        }

        $updatedItems = collect($document->items_json ?? [])
            ->values()
            ->map(function (array $item, int $index) use ($itemIndex, $product, $productUnit): array {
                if ($index !== $itemIndex) {
                    return $item;
                }

                $item['product_id'] = $product?->id;
                $item['product_name'] = $product?->name;
                $item['product_unit'] = $product?->id ? $this->normalizeUnit($productUnit) : null;

                return $item;
            })
            ->all();

        app(SefazDistributionDocumentService::class)->updateItemMappings($document, $updatedItems, Auth::id());

        $notification = Notification::make()->title('Vínculo atualizado');

        if ($document->partner_id === null) {
            $notification
                ->warning()
                ->body('O vínculo e a unidade foram salvos neste DF-e, mas o pré-vínculo automático só será reaproveitado após vincular o fornecedor.');
        } else {
            $notification
                ->success()
                ->body('O vínculo e a unidade foram salvos e serão reaproveitados nas próximas notas deste fornecedor.');
        }

        $notification->send();

        $this->resetTable();
    }

    private function unitOptionsForProduct(?int $productId): array
    {
        if (! $productId) {
            return [];
        }

        $product = Product::query()
            ->with('alternativeUnitConversions')
            ->find($productId);

        if (! $product) {
            return [];
        }

        $labels = Unit::toSelectArray();
        $options = [];

        foreach (app(ProductUnitConversionService::class)->getAvailableUnits($product) as $unit) {
            $options[$unit] = $labels[$unit] ?? $unit;
        }

        return $options;
    }

    private function defaultUnitForProduct(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        $product = Product::query()->find($productId);

        if (! $product) {
            return null;
        }

        return $product->unit?->value ?? (string) $product->unit;
    }

    private function isAllowedUnitForProduct(Product $product, ?string $unit): bool
    {
        if (! is_string($unit) || trim($unit) === '') {
            return false;
        }

        return app(ProductUnitConversionService::class)->isAllowedUnit($product, $unit);
    }

    private function formatUnitLabel(?string $unit): string
    {
        if (! $unit) {
            return '-';
        }

        return Unit::toSelectArray()[$unit] ?? $unit;
    }

    private function normalizeUnit(?string $unit): ?string
    {
        if (! is_string($unit) || trim($unit) === '') {
            return null;
        }

        return mb_strtoupper(trim($unit));
    }
}
