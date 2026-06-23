<?php

namespace App\Enum\WarrantyClaim;

enum SupplierResolution: string
{
    case NONE = 'none';
    case REPAIR = 'repair';
    case REPLACE = 'replace';
    case CREDIT = 'credit';
    case RETURN_SAME_ITEM = 'return_same_item';

    public function description(): string
    {
        return match ($this) {
            self::NONE => 'Sem definição',
            self::REPAIR => 'Conserto',
            self::REPLACE => 'Troca',
            self::CREDIT => 'Crédito',
            self::RETURN_SAME_ITEM => 'Retorno da mesma peça',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
