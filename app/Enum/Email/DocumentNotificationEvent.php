<?php

namespace App\Enum\Email;

enum DocumentNotificationEvent: string
{
    case CLOSED = 'closed';
    case CONFIRMED = 'confirmed';

    public function description(): string
    {
        return match ($this) {
            self::CLOSED => 'Encerramento',
            self::CONFIRMED => 'Confirmação',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}

