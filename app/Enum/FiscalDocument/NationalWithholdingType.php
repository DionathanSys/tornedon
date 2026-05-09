<?php

namespace App\Enum\FiscalDocument;

/**
 * Tipo de retenção dos tributos nacionais na NFS-e Nacional.
 *
 * V1 default: NOT_WITHHELD (tipo_retencao = 2).
 */
enum NationalWithholdingType: string
{
    case WITHHELD     = '1';
    case NOT_WITHHELD = '2';

    public function description(): string
    {
        return match ($this) {
            self::WITHHELD     => 'Retido',
            self::NOT_WITHHELD => 'Não Retido',
        };
    }
}
