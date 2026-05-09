<?php

namespace App\Enum\FiscalDocument;

/**
 * Tipo de destinatário do tomador na NFS-e Nacional.
 *
 * V1 suporta apenas DOMESTIC (tipo_destinatario = 0).
 */
enum RecipientType: string
{
    case DOMESTIC = '0';
    case FOREIGN  = '1';

    public function description(): string
    {
        return match ($this) {
            self::DOMESTIC => 'Nacional',
            self::FOREIGN  => 'Estrangeiro',
        };
    }
}
