<?php

namespace App\Enum\Ticket;

class Priority
{
   
    const LOW = 'low';
    const MEDIUM = 'medium';
    const HIGH = 'high';
    const URGENT = 'urgent';

    /**
     * Retorna um array compatível com Select: [value => label].
     */
    public static function toSelectArray(): array
    {
        return [
            self::LOW      => 'Baixa',
            self::MEDIUM   => 'Média',
            self::HIGH     => 'Alta',
            self::URGENT   => 'Urgente',
        ];
    }
}