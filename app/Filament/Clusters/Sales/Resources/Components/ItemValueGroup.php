<?php

namespace App\Filament\Clusters\Sales\Resources\Components;

use App\Traits\ParsesMoneyValues;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ItemValueGroup
{
    use ParsesMoneyValues;

    /**
     * Cria o grupo de campos de valores (quantidade, preco, desconto, total).
     *
     * @param  array{
     *     quantityField?: string,
     *     unitPriceField?: string,
     *     discountPercentageField?: string,
     *     discountAmountField?: string,
     *     totalAmountField?: string,
     *     subtotalField?: string,
     *     minSalePriceField?: string,
     *     serviceIdField?: string,
     *     columns?: int,
     *     showDiscount?: bool,
     *     preserveDiscountOnValueChange?: bool,
     *     enforceEffectiveMinSalePrice?: bool,
     * } $options
     */
    public static function make(array $options = []): Group
    {
        $qty = $options['quantityField'] ?? 'quantity';
        $unitPrice = $options['unitPriceField'] ?? 'unit_price';
        $discountPercentage = $options['discountPercentageField'] ?? 'discount_percentage';
        $discountAmount = $options['discountAmountField'] ?? 'discount_amount';
        $totalAmount = $options['totalAmountField'] ?? 'total_amount';
        $subtotal = $options['subtotalField'] ?? 'subtotal';
        $minSalePrice = $options['minSalePriceField'] ?? 'item.min_sale_price';
        $serviceId = $options['serviceIdField'] ?? 'item.real_service_id';
        $columns = $options['columns'] ?? 3;
        $showDiscount = $options['showDiscount'] ?? true;
        $preserveDiscountOnValueChange = $options['preserveDiscountOnValueChange'] ?? false;
        $enforceEffectiveMinSalePrice = $options['enforceEffectiveMinSalePrice'] ?? false;

        $schema = [
            TextInput::make($qty)
                ->label('Quantidade')
                ->required()
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                ->afterStateUpdated(function ($state, Set $set, Get $get) use (
                    $qty,
                    $unitPrice,
                    $discountAmount,
                    $discountPercentage,
                    $subtotal,
                    $totalAmount,
                    $preserveDiscountOnValueChange
                ) {
                    if (is_numeric($state) && $state < 0) {
                        $set($qty, number_format(0, 2, ',', '.'));
                    }

                    if ($preserveDiscountOnValueChange) {
                        self::syncDiscountFromPercentage($get, $set, $discountPercentage, $discountAmount, $subtotal);
                    } else {
                        $set($discountAmount, number_format(0, 2, ',', '.'));
                        $set($discountPercentage, number_format(0, 2, ',', '.'));
                    }

                    self::recalculate($get, $set, $qty, $unitPrice, $discountAmount, $subtotal, $totalAmount);
                }),

            Money::make($unitPrice)
                ->label('Preco Unitario')
                ->required()
                ->live(onBlur: true)
                ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                ->helperText(function (Get $get) use ($minSalePrice, $serviceId, $enforceEffectiveMinSalePrice): ?string {
                    $messages = [];
                    $min = self::parseMoneyValue($get($minSalePrice));

                    if ($min > 0) {
                        $messages[] = 'Preco minimo: R$ ' . number_format($min, 2, ',', '.');
                    }

                    if ($enforceEffectiveMinSalePrice && filled($get($serviceId)) && $min > 0) {
                        $messages[] = 'O preco efetivo apos desconto nao pode ficar abaixo deste minimo.';
                    }

                    return $messages === [] ? null : implode(' ', $messages);
                })
                ->afterStateUpdated(function ($state, Set $set, Get $get) use (
                    $qty,
                    $unitPrice,
                    $discountAmount,
                    $discountPercentage,
                    $subtotal,
                    $totalAmount,
                    $preserveDiscountOnValueChange
                ) {
                    if ($preserveDiscountOnValueChange) {
                        self::syncDiscountFromPercentage($get, $set, $discountPercentage, $discountAmount, $subtotal);
                    } else {
                        $set($discountAmount, number_format(0, 2, ',', '.'));
                        $set($discountPercentage, number_format(0, 2, ',', '.'));
                    }

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
                ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                ->helperText(function (Get $get) use ($minSalePrice, $serviceId, $qty, $unitPrice, $enforceEffectiveMinSalePrice): ?string {
                    if (! $enforceEffectiveMinSalePrice || ! filled($get($serviceId))) {
                        return null;
                    }

                    $min = self::parseMoneyValue($get($minSalePrice));
                    if ($min <= 0) {
                        return null;
                    }

                    $maxDiscount = self::calculateMaximumDiscountAmount(
                        quantity: self::parseMoneyValue($get($qty)),
                        unitPrice: self::parseMoneyValue($get($unitPrice)),
                        minSalePrice: $min,
                    );

                    return 'Desconto maximo sem violar o preco minimo: R$ ' . number_format($maxDiscount, 2, ',', '.');
                })
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    $sub = self::calculateSubtotal(
                        self::parseMoneyValue($get($qty)),
                        self::parseMoneyValue($get($unitPrice))
                    );
                    $set($subtotal, number_format($sub, 2, ',', '.'));

                    $percentage = self::parseMoneyValue($state);
                    $discount = $sub * ($percentage / 100);
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
                ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
                ->rule(self::effectiveMinSalePriceRule(
                    quantityField: $qty,
                    unitPriceField: $unitPrice,
                    discountAmountField: $discountAmount,
                    minSalePriceField: $minSalePrice,
                    serviceIdField: $serviceId,
                    enforceEffectiveMinSalePrice: $enforceEffectiveMinSalePrice,
                ))
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($discountAmount, $discountPercentage, $subtotal, $qty, $unitPrice, $totalAmount) {
                    $sub = self::calculateSubtotal(
                        self::parseMoneyValue($get($qty)),
                        self::parseMoneyValue($get($unitPrice))
                    );
                    $set($subtotal, number_format($sub, 2, ',', '.'));

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
            ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2, ',', '.'))
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
        Get $get,
        Set $set,
        string $qtyField = 'quantity',
        string $unitPriceField = 'unit_price',
        string $discountAmountField = 'discount_amount',
        string $subtotalField = 'subtotal',
        string $totalAmountField = 'total_amount',
    ): void {
        $quantity = self::parseMoneyValue($get($qtyField));
        $unitPrice = self::parseMoneyValue($get($unitPriceField));
        $discountAmount = self::parseMoneyValue($get($discountAmountField));

        $subtotal = self::calculateSubtotal($quantity, $unitPrice);
        $total = $subtotal - $discountAmount;

        $set($subtotalField, number_format($subtotal, 2, ',', '.'));
        $set($totalAmountField, number_format($total, 2, ',', '.'));
    }

    public static function calculateSubtotal(float $quantity, float $unitPrice): float
    {
        return round(max(0, $quantity) * max(0, $unitPrice), 2);
    }

    public static function calculateMaximumDiscountAmount(float $quantity, float $unitPrice, float $minSalePrice): float
    {
        $subtotal = self::calculateSubtotal($quantity, $unitPrice);

        if ($subtotal <= 0 || $minSalePrice <= 0) {
            return max(0, $subtotal);
        }

        $minimumTotal = round($minSalePrice * max(0, $quantity), 2);

        return round(max(0, $subtotal - $minimumTotal), 2);
    }

    private static function syncDiscountFromPercentage(
        Get $get,
        Set $set,
        string $discountPercentageField,
        string $discountAmountField,
        string $subtotalField,
    ): void {
        $subtotal = self::parseMoneyValue($get($subtotalField));

        if ($subtotal <= 0) {
            $subtotal = self::calculateSubtotal(
                self::parseMoneyValue($get('quantity')),
                self::parseMoneyValue($get('unit_price')),
            );
        }

        $percentage = self::parseMoneyValue($get($discountPercentageField));
        $discount = $subtotal * ($percentage / 100);

        $set($discountAmountField, number_format($discount, 2, ',', '.'));
    }

    private static function effectiveMinSalePriceRule(
        string $quantityField,
        string $unitPriceField,
        string $discountAmountField,
        string $minSalePriceField,
        string $serviceIdField,
        bool $enforceEffectiveMinSalePrice,
    ): Closure {
        return function (Get $get) use (
            $quantityField,
            $unitPriceField,
            $discountAmountField,
            $minSalePriceField,
            $serviceIdField,
            $enforceEffectiveMinSalePrice
        ) {
            return function (string $attribute, $value, Closure $fail) use (
                $get,
                $quantityField,
                $unitPriceField,
                $discountAmountField,
                $minSalePriceField,
                $serviceIdField,
                $enforceEffectiveMinSalePrice
            ) {
                if (! $enforceEffectiveMinSalePrice || ! filled($get($serviceIdField))) {
                    return;
                }

                $minSalePrice = self::parseMoneyValue($get($minSalePriceField));
                $quantity = self::parseMoneyValue($get($quantityField));

                if ($minSalePrice <= 0 || $quantity <= 0) {
                    return;
                }

                $unitPrice = self::parseMoneyValue($get($unitPriceField));
                $discountAmount = self::parseMoneyValue($get($discountAmountField));
                $effectiveUnitPrice = (($quantity * $unitPrice) - $discountAmount) / $quantity;

                if ($effectiveUnitPrice + 0.0001 >= $minSalePrice) {
                    return;
                }

                $fail(
                    'O preco efetivo apos desconto nao pode ficar abaixo de R$ ' .
                    number_format($minSalePrice, 2, ',', '.') .
                    '.'
                );
            };
        };
    }
}
