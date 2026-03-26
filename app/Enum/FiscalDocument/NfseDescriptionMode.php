<?php

namespace App\Enum\FiscalDocument;

enum NfseDescriptionMode: string
{
    case AUTO = 'auto';
    case ORDER_ONLY = 'order_only';
    case ORDER_WITH_TOTAL = 'order_with_total';

    public function description(): string
    {
        return match ($this) {
            self::AUTO => 'Automático (1 item: detalhado | 2 a 5: número + total | acima de 5: número)',
            self::ORDER_ONLY => 'Apenas número da OS',
            self::ORDER_WITH_TOTAL => 'Número da OS + valor total',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
