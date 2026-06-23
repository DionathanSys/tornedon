<?php

namespace App\Enum\WarrantyClaim;

enum Type: string
{
    case SERVICE_COMPANY = 'service_company';
    case PRODUCT_SUPPLIER = 'product_supplier';

    public function description(): string
    {
        return match ($this) {
            self::SERVICE_COMPANY => 'Garantia de serviço',
            self::PRODUCT_SUPPLIER => 'Garantia de peça com fornecedor',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SERVICE_COMPANY => 'info',
            self::PRODUCT_SUPPLIER => 'warning',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
