<?php

namespace App\Enum\Invoice;

enum CancellationReason: string
{
    case REBILLING = 'rebilling';
    case SALE_CANCELLED = 'sale_cancelled';

    public function description(): string
    {
        return match ($this) {
            self::REBILLING => 'Reversão de faturamento',
            self::SALE_CANCELLED => 'Cancelamento da venda',
        };
    }
}
