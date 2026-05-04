<?php

namespace App\Enum\CompanyCard;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case POSTED = 'posted';
    case ALLOCATED = 'allocated';
    case CANCELED = 'canceled';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::POSTED => 'Postada',
            self::ALLOCATED => 'Alocada',
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
