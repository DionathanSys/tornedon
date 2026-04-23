<?php

namespace App\Enum\FiscalDocument;

enum NfeStatus: string
{
    case QUEUED          = 'queued';
    case PENDING         = 'pending';
    case IN_PROCESSING   = 'in_processing';
    case AUTHORIZED      = 'authorized';
    case REJECTED        = 'rejected';
    case CANCELED        = 'canceled';

    public function description(): string
    {
        return match ($this) {
            self::QUEUED            => 'Na Fila',
            self::PENDING           => 'Pendente',
            self::IN_PROCESSING     => 'Em Processamento',
            self::AUTHORIZED        => 'Autorizado',
            self::REJECTED          => 'Rejeitado',
            self::CANCELED          => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::QUEUED          => 'gray',
            self::PENDING         => 'info',
            self::IN_PROCESSING   => 'warning',
            self::AUTHORIZED      => 'success',
            self::REJECTED        => 'danger',
            self::CANCELED        => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn(self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
