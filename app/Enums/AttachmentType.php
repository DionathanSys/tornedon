<?php

namespace App\Enums;

enum AttachmentType: string
{
    case FISCAL_DOCUMENT = 'fiscal_document';
    case SERVICE_PHOTO = 'service_photo';
    case CONTRACT = 'contract';
    case GENERIC = 'generic';
    case COMMITMENT_PDF = 'commitment_pdf';
    case COMMITMENT_PAYMENT_REPORT = 'commitment_payment_report';

    public function getLabel(): string
    {
        return match ($this) {
            self::FISCAL_DOCUMENT => 'Documento Fiscal',
            self::SERVICE_PHOTO => 'Foto do Serviço',
            self::CONTRACT => 'Contrato',
            self::GENERIC => 'Genérico',
            self::COMMITMENT_PDF => 'Nota de empenho',
            self::COMMITMENT_PAYMENT_REPORT => 'Relatório de pagamentos de empenhos',
        };
    }
}

// TODO Incluir projetos para a tornearia
