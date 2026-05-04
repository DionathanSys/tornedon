<?php

namespace App\Enum\CompanyCard;

enum StatementStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case CANCELED = 'canceled';

    public function description(): string
    {
        return match ($this) {
            self::OPEN => 'Aberta',
            self::CLOSED => 'Fechada',
            self::PARTIAL => 'Parcial',
            self::PAID => 'Paga',
            self::CANCELED => 'Cancelada',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
