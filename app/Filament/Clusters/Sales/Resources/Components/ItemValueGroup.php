<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemValueGroup
{
    use ParsesMoneyValues;

    /**
     * Cria o grupo de campos de valores (quantidade, preço, desconto, total).
     *
     * Compartilhado entre Quote, Requisition, ServiceOrder e ProductionOrder.
     *
     * @param  array{
     *     quantityField?:           string,
     *     unitPriceField?:          string,
     *     discountPercentageField?: string,
     *     discountAmountField?:     string,
     *     totalAmountField?:        string,
     *     subtotalField?:           string,
     *     minSalePriceField?:       string,
     *     columns?:                 int,
     *     showDiscount?:            bool,
     * } $options
     */
    public static function make(array $options = []): Group
    {
        $qty                = $options['quantityField']           ?? 'quantity';
        $unitPrice          = $options['unitPriceField']          ?? 'unit_price';
        $discountPercentage = $options['discountPercentageField'] ?? 'discount_percentage';
        $discountAmount     = $options['discountAmountField']     ?? 'discount_amount';
        $totalAmount        = $options['totalAmountField']        ?? 'total_amount';
        $subtotal           = $options['subtotalField']           ?? 'subtotal';
        $minSalePrice       = $options['minSalePriceField']       ?? 'item.min_sale_price';
        $columns            = $options['columns']                 ?? 3;
        $showDiscount       = $options['showDiscount']            ?? true;

        $schema = [
            TextInput::make($qty)
                ->label('Quantidade')
                ->required()
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    if(is_numeric($state) && $state < 0) {
                        $set($qty, number_format(0, 2, ',', '.'));
                    }
                
                    $set($discountAmount, number_format(0, 2, ',', '.'));
                    $set($discountPercentage, number_format(0, 2, ',', '.'));
                    self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                }),

            Money::make($unitPrice)
                ->label('Preço Unitário')
                ->required()
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                ->helperText(function (Get $get) use ($minSalePrice): ?string {
                    $min = (float) ($get($minSalePrice) ?? 0);
                    return $min > 0
                        ? 'Preço mínimo de venda: R$ ' . number_format($min, 2, ',', '.')
                        : null;
                })
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    $set($discountAmount, number_format(0, 2, ',', '.'));
                    $set($discountPercentage, number_format(0, 2, ',', '.'));
                    self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                }),
        ];

        if ($showDiscount) {
            $schema[] = Money::make($discountPercentage)
                ->label('Desconto (%)')
                ->columnStart(1)
                ->suffix('%')
                ->prefix(null)
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    $sub        = self::parseMoneyValue($get($subtotal));
                    $percentage = self::parseMoneyValue($state);
                    $discount   = $sub * ($percentage / 100);
                    $set($discountAmount, number_format($discount, 2, ',', '.'));
                    self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                })
                ->afterLabel(Action::make('reset_discount_percentage')
                    ->label('')
                    ->icon(Heroicon::ArrowPath)
                    ->action(function (Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                        $set($discountPercentage, number_format(0, 2, ',', '.'));
                        $set($discountAmount, number_format(0, 2, ',', '.'));
                        self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                    }));

            $schema[] = Money::make($discountAmount)
                ->label('Desconto (R$)')
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    $sub      = self::parseMoneyValue($get($subtotal));
                    $discount = self::parseMoneyValue($state);
                    if ($sub > 0) {
                        $percentage = ($discount / $sub) * 100;
                        $set($discountPercentage, number_format($percentage, 2, ',', '.'));
                    }
                    self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                });
        }

        $schema[] = Money::make($totalAmount)
            ->label('Valor Total')
            ->formatStateUsing(fn ($state) => number_format($state, 2, ',', '.'))
            ->readOnly();

        return Group::make()
            ->columns($columns)
            ->columnSpanFull()
            ->schema($schema);
    }

    /**
     * Recalcula o subtotal e o total.
     */
    public static function recalculate(
        Get    $get,
        Set    $set,
        string $qtyField            = 'quantity',
        string $unitPriceField      = 'unit_price',
        string $discountAmountField = 'discount_amount',
        string $subtotalField       = 'subtotal',
        string $totalAmountField    = 'total_amount',
    ): void {
        $quantity       = self::parseMoneyValue($get($qtyField));
        $unitPrice      = self::parseMoneyValue($get($unitPriceField));
        $discountAmount = self::parseMoneyValue($get($discountAmountField));

        $subtotal = $quantity * $unitPrice;
        $total    = $subtotal - $discountAmount;

        $set($subtotalField, number_format($subtotal, 2, ',', '.'));
        $set($totalAmountField, number_format($total, 2, ',', '.'));
    }
}
