<?php

namespace App\Enum\SefazDistributionDocument;

enum ImportStatus: string
{
    case PENDING_XML = 'pending_xml';
    case READY_TO_IMPORT = 'ready_to_import';
    case IMPORTING = 'importing';
    case IMPORTED = 'imported';
    case IGNORED = 'ignored';
    case IMPORT_ERROR = 'import_error';

    public function description(): string
    {
        return match ($this) {
            self::PENDING_XML => 'Aguardando XML',
            self::READY_TO_IMPORT => 'Pronto para importar',
            self::IMPORTING => 'Importando',
            self::IMPORTED => 'Importado',
            self::IGNORED => 'Ignorado',
            self::IMPORT_ERROR => 'Erro na importação',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING_XML => 'gray',
            self::READY_TO_IMPORT => 'success',
            self::IMPORTING => 'warning',
            self::IMPORTED => 'primary',
            self::IGNORED => 'gray',
            self::IMPORT_ERROR => 'danger',
        };
    }
}
