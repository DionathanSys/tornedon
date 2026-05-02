<?php

namespace App\Enum\PurchaseClosing;

enum Status: string
{
    case DRAFT = 'draft';
    case CLOSED = 'closed';
    case REOPENED = 'reopened';
    case CANCELED = 'canceled';

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::CLOSED => 'Fechado',
            self::REOPENED => 'Reaberto',
            self::CANCELED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::CLOSED => 'success',
            self::REOPENED => 'warning',
            self::CANCELED => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
