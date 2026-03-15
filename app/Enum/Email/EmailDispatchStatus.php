<?php

namespace App\Enum\Email;

enum EmailDispatchStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case FAILED = 'failed';
    case DEAD_LETTER = 'dead_letter';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::SENT => 'Enviado',
            self::FAILED => 'Falhou',
            self::DEAD_LETTER => 'Dead-letter',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PROCESSING => 'info',
            self::SENT => 'success',
            self::FAILED => 'danger',
            self::DEAD_LETTER => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}

