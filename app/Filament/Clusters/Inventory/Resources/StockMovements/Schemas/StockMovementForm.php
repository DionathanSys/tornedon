<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Schemas;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class StockMovementForm
{
    /**
     * Array de componentes reutilizável — usar em modais e páginas.
     * Layout: 2 colunas.
     */
    public static function schema(): array
    {
        return [
            // ── Tipo de movimento ──────────────────────────────────────────
            Select::make('type')
                ->label('Tipo de Movimento')
                ->options(
                    collect(Type::cases())
                        ->mapWithKeys(fn(Type $t) => [$t->value => $t->label()])
                        ->toArray()
                )
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if ($state) {
                        self::suggestUnitPrice(Type::from($state), (int) $get('product_stock_id'), $set);
                    }
                })
                ->columnSpan(1),

            // ── Produto / Estoque ──────────────────────────────────────────
            Select::make('product_stock_id')
                ->label('Produto')
                ->native(false)
                ->options(
                    fn(): array => ProductStock::where('company_id', Filament::getTenant()->id)
                        ->with('product')
                        ->get()
                        ->mapWithKeys(fn(ProductStock $s) => [
                            $s->id => $s->product?->name ?? "Estoque #{$s->id}",
                        ])
                        ->toArray()
                )
                ->searchable()
                ->required()
                ->live()
                ->helperText(fn(Get $get): ?string => self::stockInfo((int) $get('product_stock_id')))
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if (!$state) {
                        return;
                    }
                    $stock = ProductStock::find((int) $state);
                    if (!$stock) {
                        return;
                    }
                    // Preenche automaticamente campos ocultos
                    $set('product_id', $stock->product_id);
                    $set('company_id', $stock->company_id);

                    // Sugere custo unitário conforme tipo e histórico do estoque
                    $type = $get('type');
                    if ($type) {
                        self::suggestUnitPrice(Type::from($type), $stock->id, $set, $stock);
                    }
                })
                ->columnSpan(1),

            // ── Campos ocultos preenchidos automaticamente ─────────────────
            Hidden::make('product_id'),
            Hidden::make('company_id'),

            // ── Quantidade ─────────────────────────────────────────────────
            Money::make('quantity')
                ->label('Quantidade')
                ->prefix(null)
                ->suffix('un.')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn(Set $set, Get $get) => self::recalcTotal($set, $get))
                ->columnSpan(1),

            // ── Custo unitário ─────────────────────────────────────────────
            Money::make('unit_price')
                ->label('Custo Unitário')
                ->helperText(fn(Get $get): ?string => self::unitPriceHint((int) $get('product_stock_id')))
                ->live(onBlur: true)
                ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2, ',', '.') : 0)
                ->afterStateUpdated(fn(Set $set, Get $get) => self::recalcTotal($set, $get))
                ->columnSpan(1),

            // ── Custo total (calculado) ────────────────────────────────────
            Money::make('total_amount')
                ->label('Custo Total')
                ->readOnly()
                ->helperText('Preenchido automaticamente (qtde × custo unit.)')
                ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2, ',', '.') : 0)
                ->columnSpan(1),

            // ── Motivo ─────────────────────────────────────────────────────
            TextInput::make('reason')
                ->label('Motivo')
                ->placeholder('Ex.: Compra NF-e 12345, Consumo OP-99…')
                ->maxLength(500)
                ->columnSpan(1),

            // ── Origem / Referência ────────────────────────────────────────
            Select::make('source_type')
                ->label('Tipo de Origem')
                ->placeholder('Selecione a origem (opcional)')
                ->options([
                    'requisition'      => 'Requisição',
                    'service_order'    => 'Ordem de Serviço',
                    'production_order' => 'Ordem de Produção',
                    'quote'            => 'Orçamento',
                    'manual'           => 'Manual',
                ])
                ->default('manual')
                ->live()
                ->columnSpan(1),

            TextInput::make('source_id')
                ->label('Nº da Origem')
                ->placeholder('ID do documento de origem')
                ->numeric()
                ->minValue(1)
                ->default(0)
                ->visible(fn(Get $get): bool => filled($get('source_type')) && $get('source_type') !== 'manual')
                ->columnSpan(1),

            // ── Observações ────────────────────────────────────────────────
            Textarea::make('observations')
                ->label('Observações')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * Versão para a página de formulário completa (Create/Edit).
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Dados da Movimentação')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema(self::schema()),
            ]);
    }

    // ── Helpers internos ──────────────────────────────────────────────────

    /**
     * Sugere o custo unitário com base no tipo de movimento e histórico do estoque.
     * Entradas → último custo de compra (last_cost).
     * Saídas/consumos → custo médio ponderado (average_cost).
     */
    private static function suggestUnitPrice(Type $type, int $stockId, Set $set, ?ProductStock $stock = null): void
    {
        if (!$stockId) {
            return;
        }
        $stock ??= ProductStock::find($stockId);
        if (!$stock) {
            return;
        }

        if ($type->isInbound() && (float) $stock->last_cost > 0) {
            $set('unit_price', number_format((float) $stock->last_cost, 2, ',', '.'));
            self::recalcTotalDirect($set, null, (float) $stock->last_cost);
        } elseif ($type->isOutbound() && (float) $stock->average_cost > 0) {
            $set('unit_price', number_format((float) $stock->average_cost, 2, ',', '.'));
            self::recalcTotalDirect($set, null, (float) $stock->average_cost);
        }
    }

    /**
     * Recalcula total_amount a partir do estado atual do formulário.
     */
    private static function recalcTotal(Set $set, Get $get): void
    {
        $qty   = self::parseMoney($get('quantity'));
        $price = self::parseMoney($get('unit_price'));
        if ($qty > 0 && $price > 0) {
            $set('total_amount', number_format($qty * $price, 2, ',', '.'));
        }
    }

    /**
     * Recalcula total_amount quando temos os valores diretamente (sem Get).
     */
    private static function recalcTotalDirect(Set $set, ?float $qty, float $price): void
    {
        if ($qty !== null && $qty > 0 && $price > 0) {
            $set('total_amount', number_format($qty * $price, 2, ',', '.'));
        }
    }

    /**
     * Texto informativo sobre o estoque atual (exibido abaixo do select de produto).
     */
    private static function stockInfo(int $stockId): ?string
    {
        if (!$stockId) {
            return null;
        }
        $stock = ProductStock::find($stockId);
        if (!$stock) {
            return null;
        }

        $parts = [
            'Disponível: ' . number_format((float) $stock->quantity_available, 3, ',', '.') . ' un.',
        ];
        if ((float) $stock->quantity_reserved > 0) {
            $parts[] = 'Reservado: ' . number_format((float) $stock->quantity_reserved, 3, ',', '.') . ' un.';
        }
        if ((float) $stock->average_cost > 0) {
            $parts[] = 'Custo médio: R$ ' . number_format((float) $stock->average_cost, 2, ',', '.');
        }

        return implode('   |   ', $parts);
    }

    /**
     * Dica sobre o custo médio/último custo (exibida abaixo do campo de custo unitário).
     */
    private static function unitPriceHint(int $stockId): ?string
    {
        if (!$stockId) {
            return null;
        }
        $stock = ProductStock::find($stockId);
        if (!$stock) {
            return null;
        }

        $hints = [];
        if ((float) $stock->average_cost > 0) {
            $hints[] = 'Custo médio: R$ ' . number_format((float) $stock->average_cost, 2, ',', '.');
        }
        if ((float) $stock->last_cost > 0) {
            $hints[] = 'Último custo: R$ ' . number_format((float) $stock->last_cost, 2, ',', '.');
        }

        return $hints ? implode('   |   ', $hints) : null;
    }

    /**
     * Converte string monetária PT-BR ("1.234,56") para float.
     */
    private static function parseMoney(?string $value): float
    {
        if (!$value) {
            return 0.0;
        }
        return (float) str_replace(',', '.', str_replace('.', '', $value));
    }
}
