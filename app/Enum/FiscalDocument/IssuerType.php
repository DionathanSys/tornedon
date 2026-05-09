<?php

namespace App\Enum\FiscalDocument;

/**
 * Tipo de emitente da NFS-e Nacional.
 *
 * V1 suporta apenas PROVIDER (tipo_emitente = 1).
 */
enum IssuerType: string
{
    case PROVIDER     = '1';
    case TAKER        = '2';
    case INTERMEDIARY = '3';

    public function description(): string
    {
        return match ($this) {
            self::PROVIDER     => 'Prestador do Serviço',
            self::TAKER        => 'Tomador do Serviço',
            self::INTERMEDIARY => 'Intermediário',
        };
    }
}
