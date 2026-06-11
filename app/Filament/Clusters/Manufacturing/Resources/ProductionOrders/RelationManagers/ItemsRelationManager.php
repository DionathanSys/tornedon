<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\RelationManagers;

use App\Enum\Product\Unit;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\QuoteItem;
use App\Services\Product\ProductUnitConversionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('quote_item_id'),
                Hidden::make('sequence')
                    ->default(fn(): int => ((int) $this->getOwnerRecord()->items()->max('sequence')) + 1),
                Section::make('Item da Ordem')
                    ->columns(6)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('product_id')
                            ->label('Produto')
                            ->options(fn(): array => Product::query()
                                ->where('company_id', $this->getOwnerRecord()->company_id)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn(Product $product): array => [
                                    $product->id => trim(($product->product_code ? $product->product_code . ' - ' : '') . $product->name),
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! $state) {
                                    return;
                                }

                                $product = Product::query()->find($state);

                                if (! $product) {
                                    return;
                                }

                                $set('description', $product->name);
                                $set('unit_of_measure', $product->unit?->value ?? 'UN');
                            })
                            ->columnSpan(4),
                        TextInput::make('quantity')
                            ->label('Quantidade')
                            ->required()
                            ->numeric()
                            ->minValue(0.001)
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default(1.0),
                        Select::make('unit_of_measure')
                            ->label('Unidade')
                            ->options(function (Get $get): array {
                                $productId = (int) ($get('product_id') ?? 0);

                                return self::availableUnits($productId);
                            })
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(['md' => 1, 'lg' => 1])
                            ->default('UN'),
                        TextInput::make('description')
                            ->label('Descrição do item')
                            ->columnSpanfull(),
                        Money::make('unit_price')
                            ->label('Vlr. unitário')
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->columnSpan(['md' => 2])
                            ->helperText('Valor de venda.'),
                        Money::make('unit_cost')
                            ->label('Custo unitário')
                            ->formatStateUsing(fn($state) => number_format($state, 2, ',', '.'))
                            ->columnSpan(['md' => 2])
                            ->helperText('Custo unitário usado na entrada de estoque.'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_of_measure')
                    ->label('Unidade')
                    ->searchable(),
                TextColumn::make('unit_price')
                    ->label('Venda')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Custo')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('importQuoteItems')
                    ->label('Importar do Orçamento')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->visible(fn(): bool => filled($this->getOwnerRecord()->quote_id))
                    ->requiresConfirmation()
                    ->modalHeading('Importar itens do orçamento')
                    ->modalDescription('Serão importados apenas os itens de produto ainda não vinculados a esta ordem de produção.')
                    ->action(function (): void {
                        /** @var ProductionOrder $productionOrder */
                        $productionOrder = $this->getOwnerRecord()->loadMissing('quote.items.product');

                        if (! $productionOrder->quote_id || ! $productionOrder->quote) {
                            Notification::make()
                                ->title('Selecione um orçamento na OP antes de importar itens.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $existingQuoteItemIds = $productionOrder->items()
                            ->whereNotNull('quote_item_id')
                            ->pluck('quote_item_id')
                            ->all();

                        $quoteItems = $productionOrder->quote->items
                            ->filter(fn(QuoteItem $item): bool => $item->product_id !== null)
                            ->reject(fn(QuoteItem $item): bool => in_array($item->id, $existingQuoteItemIds, true))
                            ->sortBy('sequence')
                            ->values();

                        if ($quoteItems->isEmpty()) {
                            Notification::make()
                                ->title('Nenhum item novo do orçamento para importar.')
                                ->info()
                                ->send();

                            return;
                        }

                        foreach ($quoteItems as $quoteItem) {
                            ProductionOrderItem::query()->create([
                                'production_order_id' => $productionOrder->id,
                                'quote_item_id' => $quoteItem->id,
                                'product_id' => $quoteItem->product_id,
                                'description' => $quoteItem->resolveDescription(),
                                'quantity' => $quoteItem->quantity,
                                'unit_price' => $quoteItem->unit_price,
                                'unit_cost' => 0,
                                'discount_percentage' => $quoteItem->discount_percentage,
                                'discount_amount' => $quoteItem->discount_amount,
                                'quantity_produced' => 0,
                                'quantity_approved' => 0,
                                'quantity_rejected' => 0,
                                'unit_of_measure' => $quoteItem->unit_of_measure,
                                'technical_specifications' => $quoteItem->technical_specifications,
                                'sequence' => $quoteItem->sequence ?: (((int) $productionOrder->items()->max('sequence')) + 1),
                            ]);
                        }

                        Notification::make()
                            ->title($quoteItems->count() . ' item(ns) importado(s) do orçamento.')
                            ->success()
                            ->send();

                        $this->getOwnerRecord()->refresh();
                    }),
                CreateAction::make()
                    ->label('Adicionar Item')
                    ->modalHeading('Adicionar item da produção')
                    ->modalSubmitActionLabel('Adicionar'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateDescription('Adicione itens manualmente ou importe os itens do orçamento vinculado.');
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
                '%s | Equivale a %s %s em estoque',
                $conversion->displayRule,
                number_format($conversion->baseQuantity, 3, ',', '.'),
                $conversion->baseUnit,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
