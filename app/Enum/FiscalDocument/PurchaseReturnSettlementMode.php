<?php

namespace App\Enum\FiscalDocument;

enum PurchaseReturnSettlementMode: string
{
    case CANCEL_PAYABLE = 'cancel_payable';
    case SUPPLIER_CREDIT = 'supplier_credit';
    case REPLACE_PAYABLE = 'replace_payable';

    public function description(): string
    {
        return match ($this) {
            self::CANCEL_PAYABLE => 'Cancelar boleto/titulo em aberto',
            self::SUPPLIER_CREDIT => 'Manter pagamento e gerar crédito do fornecedor',
            self::REPLACE_PAYABLE => 'Cancelar titulo atual e gerar novo boleto',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
