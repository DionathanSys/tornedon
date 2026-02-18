<?php

namespace App\Enum\Payment;

enum Condition: string
{
    case CASH = 'a_vista';
    case DAYS_7 = '7_dias';
    case DAYS_14 = '14_dias';
    case DAYS_15 = '15_dias';
    case DAYS_21 = '21_dias';
    case DAYS_28 = '28_dias';
    case DAYS_30 = '30_dias';
    case DAYS_45 = '45_dias';
    case DAYS_60 = '60_dias';
    case DAYS_90 = '90_dias';
    case DAYS_30_60 = '30_60_dias';
    case DAYS_30_60_90 = '30_60_90_dias';
    case DAYS_30_60_90_120 = '30_60_90_120_dias';
    case INSTALLMENTS_2X = 'parcelado_2x';
    case INSTALLMENTS_3X = 'parcelado_3x';
    case INSTALLMENTS_4X = 'parcelado_4x';
    case INSTALLMENTS_5X = 'parcelado_5x';
    case INSTALLMENTS_6X = 'parcelado_6x';
    case INSTALLMENTS_9X = 'parcelado_9x';
    case INSTALLMENTS_10X = 'parcelado_10x';
    case INSTALLMENTS_12X = 'parcelado_12x';
    case INSTALLMENTS_18X = 'parcelado_18x';
    case INSTALLMENTS_24X = 'parcelado_24x';
    case CUSTOM = 'personalizado';

    public function description(): string
    {
        return match ($this) {
            self::CASH => 'À Vista',
            self::DAYS_7 => '7 dias',
            self::DAYS_14 => '14 dias',
            self::DAYS_15 => '15 dias',
            self::DAYS_21 => '21 dias',
            self::DAYS_28 => '28 dias',
            self::DAYS_30 => '30 dias',
            self::DAYS_45 => '45 dias',
            self::DAYS_60 => '60 dias',
            self::DAYS_90 => '90 dias',
            self::DAYS_30_60 => '30/60 dias',
            self::DAYS_30_60_90 => '30/60/90 dias',
            self::DAYS_30_60_90_120 => '30/60/90/120 dias',
            self::INSTALLMENTS_2X => 'Parcelado em 2x',
            self::INSTALLMENTS_3X => 'Parcelado em 3x',
            self::INSTALLMENTS_4X => 'Parcelado em 4x',
            self::INSTALLMENTS_5X => 'Parcelado em 5x',
            self::INSTALLMENTS_6X => 'Parcelado em 6x',
            self::INSTALLMENTS_9X => 'Parcelado em 9x',
            self::INSTALLMENTS_10X => 'Parcelado em 10x',
            self::INSTALLMENTS_12X => 'Parcelado em 12x',
            self::INSTALLMENTS_18X => 'Parcelado em 18x',
            self::INSTALLMENTS_24X => 'Parcelado em 24x',
            self::CUSTOM => 'Personalizado',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CASH => 'heroicon-o-bolt',
            self::DAYS_7, self::DAYS_14, self::DAYS_15, self::DAYS_21, 
            self::DAYS_28, self::DAYS_30 => 'heroicon-o-calendar',
            self::DAYS_45, self::DAYS_60, self::DAYS_90 => 'heroicon-o-calendar-days',
            self::DAYS_30_60, self::DAYS_30_60_90, self::DAYS_30_60_90_120 => 'heroicon-o-bars-3',
            self::INSTALLMENTS_2X, self::INSTALLMENTS_3X, self::INSTALLMENTS_4X, 
            self::INSTALLMENTS_5X, self::INSTALLMENTS_6X => 'heroicon-o-squares-2x2',
            self::INSTALLMENTS_9X, self::INSTALLMENTS_10X, self::INSTALLMENTS_12X,
            self::INSTALLMENTS_18X, self::INSTALLMENTS_24X => 'heroicon-o-squares-plus',
            self::CUSTOM => 'heroicon-o-cog-6-tooth',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CASH => 'success',
            self::DAYS_7, self::DAYS_14, self::DAYS_15 => 'info',
            self::DAYS_21, self::DAYS_28, self::DAYS_30 => 'primary',
            self::DAYS_45, self::DAYS_60 => 'warning',
            self::DAYS_90, self::DAYS_30_60, self::DAYS_30_60_90, self::DAYS_30_60_90_120 => 'gray',
            self::INSTALLMENTS_2X, self::INSTALLMENTS_3X, self::INSTALLMENTS_4X => 'info',
            self::INSTALLMENTS_5X, self::INSTALLMENTS_6X => 'primary',
            self::INSTALLMENTS_9X, self::INSTALLMENTS_10X, self::INSTALLMENTS_12X => 'warning',
            self::INSTALLMENTS_18X, self::INSTALLMENTS_24X => 'danger',
            self::CUSTOM => 'gray',
        };
    }

    /**
     * Retorna o número de parcelas (0 para à vista ou prazo)
     */
    public function installments(): int
    {
        return match ($this) {
            self::INSTALLMENTS_2X => 2,
            self::INSTALLMENTS_3X => 3,
            self::INSTALLMENTS_4X => 4,
            self::INSTALLMENTS_5X => 5,
            self::INSTALLMENTS_6X => 6,
            self::INSTALLMENTS_9X => 9,
            self::INSTALLMENTS_10X => 10,
            self::INSTALLMENTS_12X => 12,
            self::INSTALLMENTS_18X => 18,
            self::INSTALLMENTS_24X => 24,
            self::DAYS_30_60 => 2,
            self::DAYS_30_60_90 => 3,
            self::DAYS_30_60_90_120 => 4,
            default => 0,
        };
    }

    /**
     * Retorna o número de dias para vencimento (para condições de prazo)
     */
    public function days(): int
    {
        return match ($this) {
            self::CASH => 0,
            self::DAYS_7 => 7,
            self::DAYS_14 => 14,
            self::DAYS_15 => 15,
            self::DAYS_21 => 21,
            self::DAYS_28 => 28,
            self::DAYS_30 => 30,
            self::DAYS_45 => 45,
            self::DAYS_60 => 60,
            self::DAYS_90 => 90,
            self::DAYS_30_60 => 30,
            self::DAYS_30_60_90 => 30,
            self::DAYS_30_60_90_120 => 30,
            default => 0,
        };
    }

    /**
     * Verifica se é pagamento à vista
     */
    public function isCash(): bool
    {
        return $this === self::CASH;
    }

    /**
     * Verifica se é pagamento parcelado
     */
    public function isInstallment(): bool
    {
        return in_array($this, [
            self::INSTALLMENTS_2X,
            self::INSTALLMENTS_3X,
            self::INSTALLMENTS_4X,
            self::INSTALLMENTS_5X,
            self::INSTALLMENTS_6X,
            self::INSTALLMENTS_9X,
            self::INSTALLMENTS_10X,
            self::INSTALLMENTS_12X,
            self::INSTALLMENTS_18X,
            self::INSTALLMENTS_24X,
        ]);
    }

    /**
     * Verifica se é pagamento a prazo (com data específica)
     */
    public function isTerm(): bool
    {
        return !$this->isCash() && !$this->isInstallment() && $this !== self::CUSTOM;
    }

    /**
     * Retorna array agrupado por categoria para select
     */
    public static function toGroupedSelectArray(): array
    {
        return [
            'À Vista' => [
                self::CASH->value => self::CASH->description(),
            ],
            'Prazo (dias)' => [
                self::DAYS_7->value => self::DAYS_7->description(),
                self::DAYS_14->value => self::DAYS_14->description(),
                self::DAYS_15->value => self::DAYS_15->description(),
                self::DAYS_21->value => self::DAYS_21->description(),
                self::DAYS_28->value => self::DAYS_28->description(),
                self::DAYS_30->value => self::DAYS_30->description(),
                self::DAYS_45->value => self::DAYS_45->description(),
                self::DAYS_60->value => self::DAYS_60->description(),
                self::DAYS_90->value => self::DAYS_90->description(),
            ],
            'Múltiplos Vencimentos' => [
                self::DAYS_30_60->value => self::DAYS_30_60->description(),
                self::DAYS_30_60_90->value => self::DAYS_30_60_90->description(),
                self::DAYS_30_60_90_120->value => self::DAYS_30_60_90_120->description(),
            ],
            'Parcelado' => [
                self::INSTALLMENTS_2X->value => self::INSTALLMENTS_2X->description(),
                self::INSTALLMENTS_3X->value => self::INSTALLMENTS_3X->description(),
                self::INSTALLMENTS_4X->value => self::INSTALLMENTS_4X->description(),
                self::INSTALLMENTS_5X->value => self::INSTALLMENTS_5X->description(),
                self::INSTALLMENTS_6X->value => self::INSTALLMENTS_6X->description(),
                self::INSTALLMENTS_9X->value => self::INSTALLMENTS_9X->description(),
                self::INSTALLMENTS_10X->value => self::INSTALLMENTS_10X->description(),
                self::INSTALLMENTS_12X->value => self::INSTALLMENTS_12X->description(),
                self::INSTALLMENTS_18X->value => self::INSTALLMENTS_18X->description(),
                self::INSTALLMENTS_24X->value => self::INSTALLMENTS_24X->description(),
            ],
            'Outro' => [
                self::CUSTOM->value => self::CUSTOM->description(),
            ],
        ];
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->description(),
        ])->toArray();
    }
}
