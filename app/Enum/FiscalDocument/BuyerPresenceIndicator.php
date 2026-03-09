<?php

namespace App\Enum\FiscalDocument;

enum BuyerPresenceIndicator: string
{
    case NAO_SE_APLICA         = '0';
    case PRESENCIAL            = '1';
    case INTERNET              = '2';
    case TELEATENDIMENTO       = '3';
    case NFCE_ENTREGA          = '4';
    case PRESENCIAL_FORA_UF    = '5';
    case OUTROS                = '9';

    public function description(): string
    {
        return match ($this) {
            self::NAO_SE_APLICA      => '0 - Não se aplica',
            self::PRESENCIAL         => '1 - Presencial',
            self::INTERNET           => '2 - Internet',
            self::TELEATENDIMENTO    => '3 - Teleatendimento',
            self::NFCE_ENTREGA       => '4 - NFC-e Entrega',
            self::PRESENCIAL_FORA_UF => '5 - Presencial fora do estado',
            self::OUTROS             => '9 - Outros',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NAO_SE_APLICA      => 'gray',
            self::PRESENCIAL         => 'success',
            self::INTERNET           => 'info',
            self::TELEATENDIMENTO    => 'warning',
            self::NFCE_ENTREGA       => 'primary',
            self::PRESENCIAL_FORA_UF => 'success',
            self::OUTROS             => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
