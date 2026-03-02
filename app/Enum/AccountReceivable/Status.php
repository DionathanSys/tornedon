<?php

namespace App\Enum\AccountReceivable;

enum Status: string
{
    case PENDING = 'pending';
    case RECEIVED = 'received';
    case PARTIALLY_RECEIVED = 'partially_received';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RECEIVED => 'Recebido',
            self::PARTIALLY_RECEIVED => 'Parcialmente Recebido',
            self::OVERDUE => 'Vencido',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RECEIVED => 'success',
            self::PARTIALLY_RECEIVED => 'info',
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
