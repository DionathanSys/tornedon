<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\RelationManagers;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\QuoteItem;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('quote_item_id'),
                Select::make('product_id')
                    ->label('Produto')
                    ->options(fn (): array => Product::query()
                        ->where('company_id', $this->getOwnerRecord()->company_id)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Product $product): array => [
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
                    }),
                Textarea::make('description')
                    ->label('Descrição do item')
                    ->helperText('Use uma descrição de fabricação quando precisar detalhar além do nome do produto.')
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label('Qtd. planejada')
                    ->required()
                    ->numeric()
                    ->minValue(0.001)
                    ->helperText('Quantidade que deve ser produzida nesta OP.')
                    ->default(1.0),
                TextInput::make('quantity_produced')
                    ->label('Qtd. produzida')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Quanto ja foi efetivamente produzido.')
                    ->default(0.0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        $planned = (float) ($get('quantity') ?? 0);
                        $produced = max(0, (float) ($state ?? 0));

                        if ($planned > 0 && $produced > $planned) {
                            $produced = $planned;
                            $set('quantity_produced', $produced);
                        }

                        $approved = min((float) ($get('quantity_approved') ?? 0), $produced);
                        $rejected = min((float) ($get('quantity_rejected') ?? 0), max(0, $produced - $approved));

                        $set('quantity_approved', $approved);
                        $set('quantity_rejected', $rejected);
                    }),
                TextInput::make('quantity_approved')
                    ->label('Qtd. aprovada')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Quantidade liberada pela qualidade para venda/uso.')
                    ->default(0.0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        $produced = (float) ($get('quantity_produced') ?? 0);
                        $approved = max(0, min((float) ($state ?? 0), $produced));
                        $rejected = max(0, min((float) ($get('quantity_rejected') ?? 0), $produced - $approved));

                        $set('quantity_approved', $approved);
                        $set('quantity_rejected', $rejected);
                    }),
                TextInput::make('quantity_rejected')
                    ->label('Qtd. rejeitada')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Perdas, refugos ou itens reprovados.')
                    ->default(0.0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        $produced = (float) ($get('quantity_produced') ?? 0);
                        $approved = (float) ($get('quantity_approved') ?? 0);
                        $rejected = max(0, min((float) ($state ?? 0), max(0, $produced - $approved)));

                        $set('quantity_rejected', $rejected);
                    }),
                TextInput::make('unit_of_measure')
                    ->label('Unidade')
                    ->required()
                    ->helperText('Unidade operacional usada no apontamento.')
                    ->default('UN'),
                TextInput::make('unit_price')
                    ->label('Valor unitário estimado')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->step(0.0001)
                    ->helperText('Opcional. Ajuda quando a OP ja nasce com referencia comercial do orçamento.'),
                TextInput::make('discount_percentage')
                    ->label('Desconto (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(0)
                    ->step(0.01),
                TextInput::make('discount_amount')
                    ->label('Desconto (R$)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->step(0.01),
                TextInput::make('technical_specifications')
                    ->label('Especificações técnicas'),
                Textarea::make('production_notes')
                    ->label('Notas de produção')
                    ->helperText('Instruções, ocorrências ou observações do operador.')
                    ->columnSpanFull(),
                Textarea::make('qc_notes')
                    ->label('Notas de qualidade')
                    ->helperText('Motivos de reprovação ou observações da inspeção.')
                    ->columnSpanFull(),
                TextInput::make('actual_production_hours')
                    ->label('Horas efetivas')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('sequence')
                    ->label('Sequência')
                    ->required()
                    ->numeric()
                    ->default(fn (): int => ((int) $this->getOwnerRecord()->items()->max('sequence')) + 1),
                TextInput::make('additional_info')
                    ->label('Informações adicionais'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->columns([
                TextColumn::make('quoteItem.id')
                    ->label('Item Orçamento')
                    ->searchable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Qtd. Planejada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_produced')
                    ->label('Qtd. Produzida')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_approved')
                    ->label('Qtd. Aprovada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity_rejected')
                    ->label('Qtd. Rejeitada')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('approval_rate')
                    ->label('Aprov. (%)')
                    ->state(fn (ProductionOrderItem $record): float => round($record->getEfficiencyRate(), 2))
                    ->numeric(2, ',', '.'),
                TextColumn::make('unit_of_measure')
                    ->label('Unidade')
                    ->searchable(),
                TextColumn::make('actual_production_hours')
                    ->label('Horas')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('Seq.')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('importQuoteItems')
                    ->label('Importar do Orçamento')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->visible(fn (): bool => filled($this->getOwnerRecord()->quote_id))
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
                            ->filter(fn (QuoteItem $item): bool => $item->product_id !== null)
                            ->reject(fn (QuoteItem $item): bool => in_array($item->id, $existingQuoteItemIds, true))
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
}
