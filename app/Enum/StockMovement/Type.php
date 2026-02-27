<?php

namespace App\Enum\StockMovement;

enum Type: string
{
    case ENTRY = 'entry'; // Entrada de estoque
    case EXIT = 'exit'; // Saída de estoque
    case ADJUSTMENT = 'adjustment'; // Ajuste de estoque
    case TRANSFER = 'transfer'; // Transferência entre locais
    case RETURN = 'return'; // Devolução
    case CONSUMPTION = 'consumption'; // Consumo de produção
    case LOSS = 'loss'; // Perda/Estrago

    public function label(): string
    {
        return match ($this) {
            self::ENTRY       => 'Entrada',
            self::EXIT        => 'Saída',
            self::ADJUSTMENT  => 'Ajuste',
            self::TRANSFER    => 'Transferência',
            self::RETURN      => 'Devolução',
            self::CONSUMPTION => 'Consumo',
            self::LOSS        => 'Perda/Estrago',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ENTRY       => 'success',
            self::EXIT        => 'danger',
            self::ADJUSTMENT  => 'info',
            self::TRANSFER    => 'warning',
            self::RETURN      => 'info',
            self::CONSUMPTION => 'primary',
            self::LOSS        => 'danger',
        };
    }
}
