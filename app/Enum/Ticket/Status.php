<?php

namespace App\Enum\Ticket;

class Status
{
    const OPEN = 'open';
    const IN_PROGRESS = 'in_progress';
    const RESOLVED = 'resolved';
    const CLOSED = 'closed';

    /**
     * Retorna um array compatível com Select: [value => label].
     */
    public static function toSelectArray(): array
    {
        return [
            self::OPEN          => 'Aberto',
            self::IN_PROGRESS   => 'Em Progresso',
            self::RESOLVED      => 'Resolvido',
            self::CLOSED        => 'Fechado',
        ];
    }
}