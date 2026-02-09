<?php

namespace App\Enum\Requisition;

enum Status: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case INVOICED = 'invoiced';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::OPEN => 'Aberta',
            self::CLOSED => 'Encerrada',
            self::INVOICED => 'Faturada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'info',
            self::CLOSED => 'success',
            self::INVOICED => 'warning',
            self::CANCELLED => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
