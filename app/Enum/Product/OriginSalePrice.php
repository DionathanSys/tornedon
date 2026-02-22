<?php

namespace App\Enum\Product;

enum OriginSalePrice: string
{
    case FIXED = 'fixed';
    case CALCULATED = 'calculated';
    case CALCULATED_II = 'calculated_ii';
    case FREE = 'free';

    public function description(): string
    {
        return match ($this) {
            self::FIXED => 'Preço Fixo',
            self::CALCULATED => 'Preço Calculado (Custo + Margem)',
            self::CALCULATED_II => 'Preço Calculado (Ult. Compra + Margem)',
            self::FREE => 'Preço Livre',
        };
    }

    public static function toSelectArray(): array
    {
        $options = [];

        foreach (self::cases() as $item) {
            /** @var self $item */
            $options[$item->value] = $item->description();
        }

        return $options;
    }
}
