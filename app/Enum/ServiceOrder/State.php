<?php

namespace App\Enum\ServiceOrder;

enum State: string
{
    case OPEN = 'aberta';
    case CLOSED = 'encerrada';
    case INVOICED = 'faturada';
    case CANCELLED = 'cancelada';

    public function description(): string
    {
        return match ($this) {
            self::OPEN => 'Aberta',
            self::CLOSED => 'Encerrada',
            self::INVOICED => 'Faturada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->description(),
        ])->toArray();
    }
}