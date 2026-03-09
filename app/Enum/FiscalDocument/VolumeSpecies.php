<?php

namespace App\Enum\FiscalDocument;

enum VolumeSpecies: string
{
    case CAIXA    = 'CAIXA';
    case PACOTE   = 'PACOTE';
    case VOLUME   = 'VOLUME';
    case ROLO     = 'ROLO';
    case SACO     = 'SACO';
    case FARDO    = 'FARDO';
    case PALLET   = 'PALLET';
    case ENVELOPE = 'ENVELOPE';
    case LATA     = 'LATA';
    case TAMBOR   = 'TAMBOR';
    case BARRIL   = 'BARRIL';
    case PECA     = 'PEÇA';
    case UNIDADE  = 'UNIDADE';

    public function description(): string
    {
        return $this->value;
    }

    public function color(): string
    {
        return 'gray';
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
