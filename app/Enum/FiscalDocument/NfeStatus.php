<?php

namespace App\Enum\FiscalDocument;

enum NfeStatus: string
{
    case PENDENTE         = 'pendente';
    case EM_PROCESSAMENTO = 'em_processamento';
    case AUTORIZADO       = 'autorizado';
    case REJEITADO        = 'rejeitado';
    case CANCELADO        = 'cancelado';

    public function description(): string
    {
        return match ($this) {
            self::PENDENTE         => 'Pendente',
            self::EM_PROCESSAMENTO => 'Em Processamento',
            self::AUTORIZADO       => 'Autorizado',
            self::REJEITADO        => 'Rejeitado',
            self::CANCELADO        => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDENTE         => 'info',
            self::EM_PROCESSAMENTO => 'warning',
            self::AUTORIZADO       => 'success',
            self::REJEITADO        => 'danger',
            self::CANCELADO        => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn(self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
