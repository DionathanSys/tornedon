<?php

namespace App\Services\Shared;

use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class CommercialItemDiscountService
{
    public function apply(Collection $items, float $discountAmount, string $emptyMessage): array
    {
        if ($items->isEmpty()) {
            throw new RuntimeException($emptyMessage);
        }

        $totalItemsValue = $items->sum(function ($item) {
            return (float) $item->quantity * (float) $item->unit_price;
        });

        if ($discountAmount > $totalItemsValue) {
            throw new RuntimeException(
                'O desconto não pode ser maior que o valor total dos itens (R$ ' . number_format($totalItemsValue, 2, ',', '.') . ').'
            );
        }

        $itemCount = $items->count();
        $discountPerItem = round($discountAmount / $itemCount, 2);
        $remainingDiscount = $discountAmount;

        foreach ($items as $index => $item) {
            $currentDiscount = $index === $itemCount - 1 ? $remainingDiscount : $discountPerItem;
            $newDiscountAmount = (float) $item->discount_amount + $currentDiscount;
            $subtotal = (float) $item->quantity * (float) $item->unit_price;
            $discountPercentage = $subtotal > 0
                ? round(($newDiscountAmount / $subtotal) * 100, 2)
                : 0;

            $item->update([
                'discount_amount' => $newDiscountAmount,
                'discount_percentage' => $discountPercentage,
            ]);

            $remainingDiscount -= $currentDiscount;
        }

        return [
            'item_count' => $itemCount,
            'total_items_value' => $totalItemsValue,
        ];
    }

    public function clear(Collection $items, string $emptyMessage): int
    {
        if ($items->isEmpty()) {
            throw new RuntimeException($emptyMessage);
        }

        foreach ($items as $item) {
            $item->update([
                'discount_amount' => 0,
                'discount_percentage' => 0,
            ]);
        }

        return $items->count();
    }
}
