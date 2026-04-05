<?php

namespace App\Enum\Email;

enum DocumentNotificationEvent: string
{
    case CLOSED = 'closed';
    case CONFIRMED = 'confirmed';
    case REOPENED = 'reopened';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::CLOSED => 'Encerramento',
            self::CONFIRMED => 'Confirmação',
            self::REOPENED => 'Reabertura',
            self::CANCELLED => 'Cancelamento',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
