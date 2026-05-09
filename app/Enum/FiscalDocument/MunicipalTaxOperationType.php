<?php

namespace App\Enum\FiscalDocument;

/**
 * Tipo de operação dos tributos municipais na NFS-e Nacional.
 *
 * V1 suporta apenas TAXABLE_IN_MUNICIPALITY (tipo_operacao = 1).
 */
enum MunicipalTaxOperationType: string
{
    case TAXABLE_IN_MUNICIPALITY = '1';
    case IMMUNITY                = '2';
    case EXPORT                  = '3';

    public function description(): string
    {
        return match ($this) {
            self::TAXABLE_IN_MUNICIPALITY => 'Tributação no Município',
            self::IMMUNITY                => 'Imunidade',
            self::EXPORT                  => 'Exportação',
        };
    }
}
