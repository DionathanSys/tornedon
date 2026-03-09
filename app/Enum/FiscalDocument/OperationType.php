<?php

namespace App\Enum\FiscalDocument;

enum OperationType: string
{
    case ENTRADA = '0';
    case SAIDA   = '1';

    public function description(): string
    {
        return match ($this) {
            self::ENTRADA => 'Entrada',
            self::SAIDA   => 'Saída',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ENTRADA => 'info',
            self::SAIDA   => 'success',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
