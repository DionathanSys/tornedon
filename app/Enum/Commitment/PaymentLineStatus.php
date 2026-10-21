<?php

namespace App\Enum\Commitment;

enum PaymentLineStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';
    case DIVERGENT = 'divergent';
    case ALREADY_RECEIVED = 'already_received';
    case RECEIVED = 'received';
    case IGNORED = 'ignored';

    public function description(): string
    {
        return match ($this) {
            self::UNMATCHED => 'Não localizado', self::MATCHED => 'Compatível', self::DIVERGENT => 'Revisar divergência',
            self::ALREADY_RECEIVED => 'Já recebido', self::RECEIVED => 'Recebimento confirmado', self::IGNORED => 'Ignorado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MATCHED => 'info', self::RECEIVED, self::ALREADY_RECEIVED => 'success', self::DIVERGENT => 'warning', default => 'gray',
        };
    }
}
