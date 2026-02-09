<?php

namespace App\Enum\ProductionOrder;

enum Status: string
{
    case QUEUED = 'queued';
    case IN_PROGRESS = 'in_progress';
    case QC_CHECK = 'qc_check';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function description(): string
    {
        return match ($this) {
            self::QUEUED => 'Na Fila',
            self::IN_PROGRESS => 'Em Produção',
            self::QC_CHECK => 'Controle de Qualidade',
            self::COMPLETED => 'Concluído',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::QUEUED => 'gray',
            self::IN_PROGRESS => 'info',
            self::QC_CHECK => 'warning',
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    public static function toSelectArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->description()])
            ->toArray();
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::QUEUED => in_array($newStatus, [self::IN_PROGRESS, self::CANCELLED]),
            self::IN_PROGRESS => in_array($newStatus, [self::QC_CHECK, self::CANCELLED]),
            self::QC_CHECK => in_array($newStatus, [self::IN_PROGRESS, self::COMPLETED, self::CANCELLED]),
            self::COMPLETED => false, // Cannot transition from completed
            self::CANCELLED => false, // Cannot transition from cancelled
        };
    }
}
