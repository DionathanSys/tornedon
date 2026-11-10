<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Schemas;

use App\Models\Product;
use App\Services\Product\ProductSalePriceService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class ProductInventorySchema
{
    public static function summary(): Section
    {
        return Section::make('Situação do produto')
            ->visibleOn('edit')->columnSpanFull()->columns(4)
            ->schema([
                self::quantity('quantity_total', 'Saldo total'),
                self::quantity('quantity_reserved', 'Reservado'),
                self::quantity('quantity_available', 'Disponível'),
                TextEntry::make('inventory_status')->label('Situação')
                    ->state(function (Product $record): string {
                        $stock = $record->stock;
                        if (! $record->has_stock_control) {
                            return 'Sem controle de estoque';
                        }
                        if (! $stock) {
                            return 'Estoque não cadastrado';
                        }

                        return $stock->quantity_available < 0 ? 'Negativo' : ($stock->quantity_available <= $stock->quantity_minimum ? 'Abaixo do mínimo' : 'Normal');
                    })->badge()->color(fn (string $state): string => match ($state) {
                        'Negativo' => 'danger', 'Abaixo do mínimo' => 'warning', 'Normal' => 'success', default => 'gray',
                    }),
                ...self::costs(),
                TextEntry::make('current_sale_price')->label('Preço de venda atual')
                    ->state(fn (Product $record) => app(ProductSalePriceService::class)->resolve($record, $record->stock))
                    ->money('BRL')->placeholder('Preço livre'),
            ]);
    }

    public static function stock(): array
    {
        return [
            Section::make('Controle e reposição')->columns(2)->schema([
                Toggle::make('has_stock_control')->label('Controla estoque')->live()->default(false),
                Toggle::make('stock.allow_negative')->label('Permite estoque negativo')->default(false)
                    ->helperText('Permite reservar e movimentar além do saldo disponível.')
                    ->visible(fn (Get $get): bool => (bool) $get('has_stock_control')),
                TextInput::make('stock.quantity_minimum')->label('Estoque mínimo')->numeric()->minValue(0)->step(0.001)->default(0)->required()
                    ->visible(fn (Get $get): bool => (bool) $get('has_stock_control')),
                TextInput::make('stock.quantity_maximum')->label('Estoque máximo')->numeric()->minValue(0)->step(0.001)->gte('stock.quantity_minimum')
                    ->visible(fn (Get $get): bool => (bool) $get('has_stock_control')),
            ]),
            Section::make('Saldos')->visibleOn('edit')->columns(3)->schema([
                self::quantity('quantity_total', 'Saldo total'),
                self::quantity('quantity_reserved', 'Reservado'),
                self::quantity('quantity_available', 'Disponível'),
                TextEntry::make('stock_help')->hiddenLabel()->columnSpanFull()
                    ->state('Registre entradas, saídas, perdas e contagens em Movimentações. Consulte os documentos que comprometem o saldo em Reservas.'),
            ]),
        ];
    }

    public static function costs(): array
    {
        return [
            TextEntry::make('inventory_average_cost')->label('Custo médio')
                ->state(fn (Product $record) => $record->stock?->average_cost)->money('BRL', decimalPlaces: 4)->placeholder('Sem custo'),
            TextEntry::make('inventory_last_cost')->label('Último custo')
                ->state(fn (Product $record) => $record->stock?->last_cost)->money('BRL', decimalPlaces: 4)->placeholder('Sem custo'),
            TextEntry::make('inventory_value')->label('Valor do saldo a custo médio (estimativa)')
                ->state(function (Product $record): ?float {
                    $stock = $record->stock;

                    return $stock ? (float) $stock->quantity_total * (float) $stock->average_cost : null;
                })->money('BRL')->placeholder('Sem estoque'),
        ];
    }

    public static function preview(Get $get, ?Product $record): string
    {
        if (! $record) {
            return 'A simulação estará disponível após cadastrar o produto.';
        }
        $number = function ($value): float {
            if (is_string($value) && str_contains($value, ',')) {
                $value = str_replace(',', '.', str_replace(['R$', ' ', '.'], '', $value));
            }

            return (float) $value;
        };
        $product = clone $record;
        $product->origin_sale_price = $get('origin_sale_price');
        $product->profit_margin = $number($get('profit_margin'));
        $product->min_sale_price = $number($get('min_sale_price'));
        $product->sale_price_value = $number($get('sale_price_value'));
        $stock = $record->stock;
        $price = app(ProductSalePriceService::class)->resolve($product, $stock);
        if ($price === null) {
            return 'Preço livre: informado na venda.';
        }
        $cost = $product->origin_sale_price?->value === 'calculated_ii'
            ? ((float) $stock?->last_cost ?: (float) $stock?->average_cost) : (float) $stock?->average_cost;
        $format = fn (float $value): string => 'R$ '.number_format($value, 2, ',', '.');
        $result = 'Prévia: '.$format($price).' | Custo de referência: '.$format($cost);
        if ($price > 0 && $cost > 0) {
            $result .= ' | Resultado bruto/un.: '.$format($price - $cost).' | Margem sobre a venda: '.number_format(($price - $cost) / $price * 100, 2, ',', '.').'%';
        }

        return $result.' — Considera o preço mínimo. Prévia antes de salvar; não inclui despesas e impostos.';
    }

    private static function quantity(string $field, string $label): TextEntry
    {
        return TextEntry::make('inventory_'.$field)->label($label)
            ->state(function (Product $record) use ($field): string {
                $stock = $record->stock;

                return $stock && $record->has_stock_control
                    ? number_format((float) $stock->{$field}, 3, ',', '.').' '.$record->unit->value : 'Sem controle';
            });
    }
}
