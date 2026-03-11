<?php

namespace App\Enum\FiscalDocument;

/**
 * Regime Especial de Tributação para NFS-e.
 *
 * Modelo Municipal (Chapecó-SC): valores 1–6
 * Modelo Nacional: valores 0–6
 */
enum NfseRegimeTributacao: string
{
    case NENHUM                    = '0';
    case MICROEMPRESA_MUNICIPAL    = '1';
    case ESTIMATIVA                = '2';
    case SOCIEDADE_PROFISSIONAIS   = '3';
    case COOPERATIVA               = '4';
    case MEI                       = '5';
    case ME_EPP                    = '6';

    public function description(): string
    {
        return match ($this) {
            self::NENHUM                  => 'Nenhum',
            self::MICROEMPRESA_MUNICIPAL  => 'Microempresa Municipal',
            self::ESTIMATIVA              => 'Estimativa',
            self::SOCIEDADE_PROFISSIONAIS => 'Sociedade de Profissionais',
            self::COOPERATIVA             => 'Cooperativa',
            self::MEI                     => 'Microempresário Individual (MEI)',
            self::ME_EPP                  => 'Microempresário e Empresa de Pequeno Porte (ME EPP)',
        };
    }

    /**
     * Descrição para modelo municipal (sem opção "Nenhum").
     */
    public function descriptionMunicipal(): string
    {
        return match ($this) {
            self::MICROEMPRESA_MUNICIPAL  => 'Microempresa Municipal',
            self::ESTIMATIVA              => 'Estimativa',
            self::SOCIEDADE_PROFISSIONAIS => 'Sociedade de Profissionais',
            self::COOPERATIVA             => 'Cooperativa',
            self::MEI                     => 'Microempresário Individual (MEI)',
            self::ME_EPP                  => 'Microempresário e Empresa de Pequeno Porte (ME EPP)',
            default                       => $this->description(),
        };
    }

    /**
     * Descrição para modelo nacional.
     */
    public function descriptionNacional(): string
    {
        return match ($this) {
            self::NENHUM                  => 'Nenhum',
            self::COOPERATIVA             => 'Ato Cooperado (Cooperativa)',
            self::ESTIMATIVA              => 'Estimativa',
            self::MICROEMPRESA_MUNICIPAL  => 'Microempresa Municipal',
            self::MEI                     => 'Profissional Autônomo',
            self::ME_EPP                  => 'Sociedade de Profissionais',
            default                       => $this->description(),
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }

    /**
     * Casos válidos para o modelo municipal (sem "Nenhum").
     */
    public static function municipalCases(): array
    {
        return array_filter(self::cases(), fn (self $case) => $case !== self::NENHUM);
    }
}
