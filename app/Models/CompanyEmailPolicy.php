<?php

namespace App\Models;

use App\Enum\Email\DocumentNotificationEvent;
use App\Enum\Email\DocumentNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEmailPolicy extends Model
{
    protected $fillable = [
        'company_id',
        'document_type',
        'event',
        'enabled',
        'default_to',
        'default_cc',
        'default_bcc',
        'subject_template',
        'body_template',
        'required_attachments',
        'optional_attachments',
        'max_total_size_mb',
        'allowed_mime_types',
        'fallback_mode',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'required_attachments' => 'array',
        'optional_attachments' => 'array',
        'allowed_mime_types' => 'array',
        'max_total_size_mb' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function resolve(
        int $companyId,
        DocumentNotificationType|string $documentType,
        DocumentNotificationEvent|string $event,
    ): self {
        $documentTypeValue = $documentType instanceof DocumentNotificationType ? $documentType->value : (string) $documentType;
        $eventValue = $event instanceof DocumentNotificationEvent ? $event->value : (string) $event;

        return self::query()->firstOrCreate(
            [
                'company_id'        => $companyId,
                'document_type'     => $documentTypeValue,
                'event'             => $eventValue,
            ],
            self::defaults($documentTypeValue, $eventValue),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults(
        DocumentNotificationType|string $documentType,
        DocumentNotificationEvent|string $event,
    ): array {
        $documentTypeValue = $documentType instanceof DocumentNotificationType ? $documentType->value : (string) $documentType;
        $eventValue = $event instanceof DocumentNotificationEvent ? $event->value : (string) $event;

        $allowedMimeTypes = [
            'application/pdf',
            'application/xml',
            'text/xml',
            'text/plain',
        ];

        return match ([$documentTypeValue, $eventValue]) {
            [DocumentNotificationType::SERVICE_ORDER->value, DocumentNotificationEvent::CLOSED->value] => [
                'enabled' => true,
                'subject_template' => 'Ordem de Serviço {{document_number}} encerrada',
                'body_template' => 'Olá {{partner_name}},<br><br>A ordem de serviço {{document_number}} foi encerrada e segue em anexo.',
                'required_attachments' => ['pdf'],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::SERVICE_ORDER->value, DocumentNotificationEvent::REOPENED->value] => [
                'enabled' => false,
                'subject_template' => 'Ordem de Serviço {{document_number}} reaberta',
                'body_template' => 'Olá {{partner_name}},<br><br>A ordem de serviço {{document_number}} foi reaberta.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::SERVICE_ORDER->value, DocumentNotificationEvent::CANCELLED->value] => [
                'enabled' => false,
                'subject_template' => 'Ordem de Serviço {{document_number}} cancelada',
                'body_template' => 'Olá {{partner_name}},<br><br>A ordem de serviço {{document_number}} foi cancelada.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::REQUISITION->value, DocumentNotificationEvent::CLOSED->value] => [
                'enabled' => true,
                'subject_template' => 'Requisição {{document_number}} encerrada',
                'body_template' => 'Olá {{partner_name}},<br><br>A requisição {{document_number}} foi encerrada e segue em anexo.',
                'required_attachments' => ['pdf'],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::REQUISITION->value, DocumentNotificationEvent::REOPENED->value] => [
                'enabled' => false,
                'subject_template' => 'Requisição {{document_number}} reaberta',
                'body_template' => 'Olá {{partner_name}},<br><br>A requisição {{document_number}} foi reaberta.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::REQUISITION->value, DocumentNotificationEvent::CANCELLED->value] => [
                'enabled' => false,
                'subject_template' => 'Requisição {{document_number}} cancelada',
                'body_template' => 'Olá {{partner_name}},<br><br>A requisição {{document_number}} foi cancelada.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::PRODUCTION_ORDER->value, DocumentNotificationEvent::CLOSED->value] => [
                'enabled' => true,
                'subject_template' => 'Ordem de Produção {{document_number}} encerrada',
                'body_template' => 'Olá {{partner_name}},<br><br>A ordem de produção {{document_number}} foi encerrada e segue em anexo.',
                'required_attachments' => ['pdf'],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::PRODUCTION_ORDER->value, DocumentNotificationEvent::CANCELLED->value] => [
                'enabled' => false,
                'subject_template' => 'Ordem de Produção {{document_number}} cancelada',
                'body_template' => 'Olá {{partner_name}},<br><br>A ordem de produção {{document_number}} foi cancelada.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::INVOICE->value, DocumentNotificationEvent::CONFIRMED->value] => [
                'enabled' => true,
                'subject_template' => 'Fatura {{document_number}} confirmada',
                'body_template' => 'Olá {{partner_name}},<br><br>A fatura {{document_number}} foi confirmada e segue em anexo.',
                'required_attachments' => ['pdf'],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            [DocumentNotificationType::FISCAL_DOCUMENT->value, DocumentNotificationEvent::CONFIRMED->value] => [
                'enabled' => true,
                'subject_template' => 'Nota Fiscal {{document_number}} confirmada',
                'body_template' => 'Olá {{partner_name}},<br><br>A nota fiscal {{document_number}} foi confirmada e segue em anexo.',
                'required_attachments' => ['danfe'],
                'optional_attachments' => ['xml'],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
            default => [
                'enabled' => true,
                'subject_template' => 'Documento {{document_number}} atualizado',
                'body_template' => 'Olá {{partner_name}},<br><br>O documento {{document_number}} foi atualizado.',
                'required_attachments' => [],
                'optional_attachments' => [],
                'max_total_size_mb' => 20,
                'allowed_mime_types' => $allowedMimeTypes,
                'fallback_mode' => 'signed_link',
            ],
        };
    }
}
