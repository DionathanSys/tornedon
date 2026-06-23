<?php

namespace App\Enum\WarrantyClaim;

enum Responsibility: string
{
    case COMPANY = 'company';
    case SUPPLIER = 'supplier';
    case MANUFACTURER = 'manufacturer';
    case CUSTOMER = 'customer';

    public function description(): string
    {
        return match ($this) {
            self::COMPANY => 'Empresa',
            self::SUPPLIER => 'Fornecedor',
            self::MANUFACTURER => 'Fabricante',
            self::CUSTOMER => 'Cliente',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
