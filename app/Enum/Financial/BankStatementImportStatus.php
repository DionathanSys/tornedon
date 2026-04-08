<?php

namespace App\Enum\Financial;

enum BankStatementImportStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::COMPLETED => 'Concluido',
            self::FAILED => 'Falhou',
        };
    }
}
