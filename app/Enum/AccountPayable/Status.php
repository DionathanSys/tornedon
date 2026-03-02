<?php

namespace App\Enum\AccountPayable;

enum Status: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case PARTIALLY_PAID = 'partially_paid';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PAID => 'Pago',
            self::PARTIALLY_PAID => 'Parcialmente Pago',
            self::OVERDUE => 'Vencido',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PAID => 'success',
            self::PARTIALLY_PAID => 'info',
            self::OVERDUE => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn(self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
