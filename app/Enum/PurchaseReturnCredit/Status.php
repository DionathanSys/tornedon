<?php

namespace App\Enum\PurchaseReturnCredit;

enum Status: string
{
    case OPEN = 'open';
    case PARTIALLY_USED = 'partially_used';
    case FULLY_USED = 'fully_used';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::OPEN => 'Aberto',
            self::PARTIALLY_USED => 'Parcialmente utilizado',
            self::FULLY_USED => 'Totalmente utilizado',
            self::CANCELLED => 'Cancelado',
        };
    }
}
