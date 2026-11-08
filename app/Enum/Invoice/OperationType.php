<?php

namespace App\Enum\Invoice;

enum OperationType: string
{
    case RETURN_TO_DRAFT = 'return_to_draft';
    case REVERSE_BILLING = 'reverse_billing';
    case CANCEL_SALE = 'cancel_sale';

    public function description(): string
    {
        return match ($this) {
            self::RETURN_TO_DRAFT => 'Desfazer confirmação',
            self::REVERSE_BILLING => 'Reverter Faturamento',
            self::CANCEL_SALE => 'Cancelar Venda',
        };
    }
}
