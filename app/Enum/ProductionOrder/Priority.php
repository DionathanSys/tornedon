<?php

namespace App\Enum\ProductionOrder;

enum Priority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function description(): string
    {
        return match ($this) {
            self::LOW => 'Baixa',
            self::NORMAL => 'Normal',
            self::HIGH => 'Alta',
            self::URGENT => 'Urgente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'gray',
            self::NORMAL => 'info',
            self::HIGH => 'warning',
            self::URGENT => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
