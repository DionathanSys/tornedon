<?php

namespace App\Enum\Commitment;

enum ImportType: string
{
    case COMMITMENTS = 'commitments';
    case PAYMENTS = 'payments';

    public function description(): string
    {
        return $this === self::COMMITMENTS ? 'Empenhos' : 'Pagamentos de empenhos';
    }
}
