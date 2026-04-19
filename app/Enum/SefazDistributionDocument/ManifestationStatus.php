<?php

namespace App\Enum\SefazDistributionDocument;

enum ManifestationStatus: string
{
    case NOT_REQUIRED = 'not_required';
    case PENDING = 'pending';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case FAILED = 'failed';

    public function description(): string
    {
        return match ($this) {
            self::NOT_REQUIRED => 'Não aplicável',
            self::PENDING => 'Pendente',
            self::SENT => 'Enviada',
            self::ACCEPTED => 'Aceita',
            self::REJECTED => 'Rejeitada',
            self::FAILED => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NOT_REQUIRED => 'gray',
            self::PENDING => 'warning',
            self::SENT => 'info',
            self::ACCEPTED => 'success',
            self::REJECTED => 'danger',
            self::FAILED => 'danger',
        };
    }
}
