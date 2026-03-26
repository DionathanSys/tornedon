<?php

namespace App\Enum\Email;

enum DocumentNotificationType: string
{
    case SERVICE_ORDER = 'service_order';
    case REQUISITION = 'requisition';
    case PRODUCTION_ORDER = 'production_order';
    case INVOICE = 'invoice';
    case FISCAL_DOCUMENT = 'fiscal_document';

    public function description(): string
    {
        return match ($this) {
            self::SERVICE_ORDER => 'Ordem de Serviço',
            self::REQUISITION => 'Requisição',
            self::PRODUCTION_ORDER => 'Ordem de Produção',
            self::INVOICE => 'Fatura',
            self::FISCAL_DOCUMENT => 'Documento Fiscal',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->description()])
            ->toArray();
    }
}
