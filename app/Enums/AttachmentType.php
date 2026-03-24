<?php

namespace App\Enums;

enum AttachmentType: string
{
    case FISCAL_DOCUMENT = 'fiscal_document';
    case SERVICE_PHOTO = 'service_photo';
    case CONTRACT = 'contract';
    case GENERIC = 'generic';

    public function getLabel(): string
    {
        return match ($this) {
            self::FISCAL_DOCUMENT => 'Documento Fiscal',
            self::SERVICE_PHOTO => 'Foto do Serviço',
            self::CONTRACT => 'Contrato',
            self::GENERIC => 'Genérico',
        };
    }
}


//TODO Incluir projetos para a tornearia