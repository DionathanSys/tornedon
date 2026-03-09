<?php

namespace App\Enum\FiscalDocument;

enum FreightModality: string
{
    case CIF_EMITENTE             = '0';
    case FOB_DESTINATARIO         = '1';
    case TERCEIROS                = '2';
    case PROPRIO_REMETENTE        = '3';
    case PROPRIO_DESTINATARIO     = '4';
    case SEM_FRETE                = '9';

    public function description(): string
    {
        return match ($this) {
            self::CIF_EMITENTE         => '0 - CIF (Emitente)',
            self::FOB_DESTINATARIO     => '1 - FOB (Destinatário)',
            self::TERCEIROS            => '2 - Terceiros',
            self::PROPRIO_REMETENTE    => '3 - Próprio (Remetente)',
            self::PROPRIO_DESTINATARIO => '4 - Próprio (Destinatário)',
            self::SEM_FRETE            => '9 - Sem frete',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CIF_EMITENTE         => 'primary',
            self::FOB_DESTINATARIO     => 'info',
            self::TERCEIROS            => 'warning',
            self::PROPRIO_REMETENTE    => 'success',
            self::PROPRIO_DESTINATARIO => 'success',
            self::SEM_FRETE            => 'gray',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
