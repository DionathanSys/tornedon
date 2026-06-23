<?php

namespace App\Enum\WarrantyClaim;

enum SupplierDecision: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case PARTIAL = 'partial';
    case REJECTED = 'rejected';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::APPROVED => 'Aprovada',
            self::PARTIAL => 'Parcial',
            self::REJECTED => 'Recusada',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
