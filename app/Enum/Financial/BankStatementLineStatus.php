<?php

namespace App\Enum\Financial;

enum BankStatementLineStatus: string
{
    case PENDING = 'pending';
    case RECONCILED = 'reconciled';
    case IGNORED = 'ignored';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RECONCILED => 'Conciliado',
            self::IGNORED => 'Ignorado',
        };
    }
}
