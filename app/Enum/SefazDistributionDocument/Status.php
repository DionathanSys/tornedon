<?php

namespace App\Enum\SefazDistributionDocument;

enum Status: string
{
    case DETECTED_SUMMARY = 'detected_summary';
    case MANIFESTATION_PENDING = 'manifestation_pending';
    case MANIFESTED_WAITING_FULL_XML = 'manifested_waiting_full_xml';
    case FULL_XML_AVAILABLE = 'full_xml_available';
    case IMPORTED = 'imported';
    case ERROR = 'error';

    public function description(): string
    {
        return match ($this) {
            self::DETECTED_SUMMARY => 'Resumo detectado',
            self::MANIFESTATION_PENDING => 'Manifestação pendente',
            self::MANIFESTED_WAITING_FULL_XML => 'Aguardando XML completo',
            self::FULL_XML_AVAILABLE => 'Pronto para importar',
            self::IMPORTED => 'Importado',
            self::ERROR => 'Erro',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DETECTED_SUMMARY => 'gray',
            self::MANIFESTATION_PENDING => 'warning',
            self::MANIFESTED_WAITING_FULL_XML => 'info',
            self::FULL_XML_AVAILABLE => 'success',
            self::IMPORTED => 'primary',
            self::ERROR => 'danger',
        };
    }
}
