<?php

namespace App\Livewire;

use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\SefazDistributionDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\TableComponent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SefazDistributionDocumentItemsTable extends TableComponent
{
    public int $documentId;

    /**
     * @var array<int, string>
     */
    public array $productOptions = [];

    public function mount(int $documentId): void
    {
        $document = $this->getDocument();

        $this->productOptions = Product::query()
            ->where('company_id', $document->company_id)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => trim(($product->product_code ? "[{$product->product_code}] " : '') . $product->name),
            ])
            ->all();
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
                TextColumn::make('product_name')
                    ->label('Produto vinculado')
                    ->state(fn (array $record): string => $record['product_name'] ?: 'Não vinculado')
                    ->badge()
                    ->color(fn (array $record): string => $record['product_id'] ? 'success' : 'gray')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('link')
                    ->label(fn (array $record): string => $record['product_id'] ? 'Alterar vínculo' : 'Vincular')
                    ->icon('heroicon-o-link')
                    ->schema([
                        Select::make('product_id')
                            ->label('Produto interno')
                            ->options(fn (): array => $this->productOptions)
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ])
                    ->fillForm(fn (array $record): array => [
                        'product_id' => $record['product_id'],
                    ])
                    ->action(function (array $data, array $record): void {
                        $this->updateItemMapping(
                            itemIndex: (int) $record['item_index'],
                            productId: (int) $data['product_id'],
                        );
                    }),
                Action::make('unlink')
                    ->label('Remover vínculo')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => filled($record['product_id']))
                    ->action(function (array $record): void {
                        $this->updateItemMapping(
                            itemIndex: (int) $record['item_index'],
                            productId: null,
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
                        'product_id' => $item['product_id'] ?? null,
                        'product_name' => $item['product_name'] ?? null,
                    ],
                ];
            });
    }

    protected function updateItemMapping(int $itemIndex, ?int $productId): void
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

        $updatedItems = collect($document->items_json ?? [])
            ->values()
            ->map(function (array $item, int $index) use ($itemIndex, $product): array {
                if ($index !== $itemIndex) {
                    return $item;
                }

                $item['product_id'] = $product?->id;
                $item['product_name'] = $product?->name;

                return $item;
            })
            ->all();

        app(SefazDistributionDocumentService::class)->updateItemMappings($document, $updatedItems, Auth::id());

        $notification = Notification::make()->title('Vínculo atualizado');

        if ($document->partner_id === null) {
            $notification
                ->warning()
                ->body('O vínculo foi salvo neste DF-e, mas o pré-vínculo automático só será reaproveitado após vincular o fornecedor.');
        } else {
            $notification
                ->success()
                ->body('O vínculo foi salvo e será reaproveitado nas próximas notas deste fornecedor.');
        }

        $notification->send();

        $this->resetTable();
    }
}
