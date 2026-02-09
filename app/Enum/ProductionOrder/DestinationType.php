<?php

namespace App\Enum\ProductionOrder;

enum DestinationType: string
{
    case STOCK = 'stock';
    case DIRECT_DELIVERY = 'direct_delivery';

    public function description(): string
    {
        return match ($this) {
            self::STOCK => 'Estoque',
            self::DIRECT_DELIVERY => 'Entrega Direta',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::STOCK => 'info',
            self::DIRECT_DELIVERY => 'success',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
