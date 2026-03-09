<?php

namespace App\Enum\FiscalDocument;

enum IssuePurpose: string
{
    case NORMAL       = '1';
    case COMPLEMENTAR = '2';
    case AJUSTE       = '3';
    case DEVOLUCAO    = '4';

    public function description(): string
    {
        return match ($this) {
            self::NORMAL       => '1 - Normal',
            self::COMPLEMENTAR => '2 - Complementar',
            self::AJUSTE       => '3 - Ajuste',
            self::DEVOLUCAO    => '4 - Devolução',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NORMAL       => 'success',
            self::COMPLEMENTAR => 'info',
            self::AJUSTE       => 'warning',
            self::DEVOLUCAO    => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
