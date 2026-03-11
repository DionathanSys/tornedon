<?php

namespace App\Enum\FiscalDocument;

enum NfseModel: string
{
    case MUNICIPAL = 'municipal';
    case NACIONAL  = 'nacional';

    public function description(): string
    {
        return match ($this) {
            self::MUNICIPAL => 'Municipal',
            self::NACIONAL  => 'Nacional',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
