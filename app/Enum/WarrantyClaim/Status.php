<?php

namespace App\Enum\WarrantyClaim;

enum Status: string
{
    case DRAFT = 'draft';
    case UNDER_ANALYSIS = 'under_analysis';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case INTERNAL_REPAIR = 'internal_repair';
    case SENT_TO_SUPPLIER = 'sent_to_supplier';
    case AWAITING_SUPPLIER_RETURN = 'awaiting_supplier_return';
    case RETURNED_FROM_SUPPLIER = 'returned_from_supplier';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::UNDER_ANALYSIS => 'Em análise',
            self::APPROVED => 'Aprovada',
            self::REJECTED => 'Recusada',
            self::INTERNAL_REPAIR => 'Reparo interno',
            self::SENT_TO_SUPPLIER => 'Enviada ao fornecedor',
            self::AWAITING_SUPPLIER_RETURN => 'Aguardando retorno do fornecedor',
            self::RETURNED_FROM_SUPPLIER => 'Retornada do fornecedor',
            self::RESOLVED => 'Resolvida',
            self::CLOSED => 'Encerrada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::UNDER_ANALYSIS => 'info',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::INTERNAL_REPAIR => 'warning',
            self::SENT_TO_SUPPLIER => 'warning',
            self::AWAITING_SUPPLIER_RETURN => 'warning',
            self::RETURNED_FROM_SUPPLIER => 'info',
            self::RESOLVED => 'success',
            self::CLOSED => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
