<?php

namespace App\Enum\Tax;

enum TaxRegime: string
{
    case MEI = 'mei';
    case SIMPLES_NACIONAL = 'simples_nacional';
    case LUCRO_PRESUMIDO = 'lucro_presumido';
    case LUCRO_REAL = 'lucro_real';

    public function description(): string
    {
        return match ($this) {
            self::MEI               => 'MEI - Microempreendedor Individual',
            self::SIMPLES_NACIONAL  => 'Simples Nacional',
            self::LUCRO_PRESUMIDO   => 'Lucro Presumido',
            self::LUCRO_REAL        => 'Lucro Real',
        };
    }

    /**
     * Código de Regime Tributário (CRT) conforme NF-e.
     * 1 = Simples Nacional (inclui MEI)
     * 3 = Regime Normal (Lucro Presumido / Lucro Real)
     */
    public function crt(): int
    {
        return match ($this) {
            self::MEI, self::SIMPLES_NACIONAL => 1,
            self::LUCRO_PRESUMIDO, self::LUCRO_REAL => 3,
        };
    }

    /**
     * Indica se o regime utiliza CSOSN (Simples Nacional) em vez de CST ICMS.
     */
    public function usaCsosn(): bool
    {
        return match ($this) {
            self::MEI, self::SIMPLES_NACIONAL => true,
            self::LUCRO_PRESUMIDO, self::LUCRO_REAL => false,
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }
}
