<?php

namespace App\Enum\FiscalDocument;

enum DocumentModel: string
{
    case NFE  = 'nfe';
    case NFSE = 'nfse';

    public function description(): string
    {
        return match ($this) {
            self::NFE  => 'NF-e',
            self::NFSE => 'NFS-e',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NFE  => 'primary',
            self::NFSE => 'info',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
