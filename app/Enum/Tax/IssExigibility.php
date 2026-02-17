<?php

namespace App\Enum\Tax;

enum IssExigibility: string
{
    case EXIGIVEL             = '1';
    case NAO_INCIDENCIA       = '2';
    case ISENCAO              = '3';
    case EXPORTACAO           = '4';
    case IMUNIDADE            = '5';
    case EXIGIBILIDADE_SUSPENSA_DECISAO_JUDICIAL  = '6';
    case EXIGIBILIDADE_SUSPENSA_PROCESSO_ADMIN    = '7';

    public function description(): string
    {
        return match ($this) {
            self::EXIGIVEL            => 'Exigível',
            self::NAO_INCIDENCIA      => 'Não incidência',
            self::ISENCAO             => 'Isenção',
            self::EXPORTACAO          => 'Exportação',
            self::IMUNIDADE           => 'Imunidade',
            self::EXIGIBILIDADE_SUSPENSA_DECISAO_JUDICIAL => 'Exigibilidade suspensa por decisão judicial',
            self::EXIGIBILIDADE_SUSPENSA_PROCESSO_ADMIN   => 'Exigibilidade suspensa por processo administrativo',
        };
    }

    /**
     * Retorna um array compatível com Select: [value => label].
     */
    public static function toSelectArray(): array
    {
        $options = [];

        foreach (self::cases() as $item) {
            $options[$item->value] = $item->description();
        }

        return $options;
    }
}
