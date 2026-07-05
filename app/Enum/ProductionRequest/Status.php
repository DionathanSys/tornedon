<?php

namespace App\Enum\ProductionRequest;

enum Status: string
{
    case OPEN = 'open';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::OPEN => 'Aberto',
            self::DELIVERED => 'Entregue',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::DELIVERED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
