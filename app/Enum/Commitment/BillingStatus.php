<?php

namespace App\Enum\Commitment;

enum BillingStatus: string
{
    case PENDING = 'pending';
    case INVOICED = 'invoiced';
    case CONFIRMED = 'confirmed';
    case EMISSION_QUEUED = 'emission_queued';
    case FAILED = 'failed';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Aguardando faturamento',
            self::INVOICED => 'OS faturada', self::CONFIRMED => 'Fatura confirmada',
            self::EMISSION_QUEUED => 'NFS-e encaminhada', self::FAILED => 'Falha — revisar e retomar',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FAILED => 'danger', self::EMISSION_QUEUED => 'success', self::PENDING => 'gray',
            default => 'warning',
        };
    }
}
