<?php

namespace App\Enum\Financial;

enum PixChargeStatus: string
{
    case PENDING_REGISTRATION = 'pending_registration';
    case REGISTERED = 'registered';
    case REGISTRATION_FAILED = 'registration_failed';
    case PAID = 'paid';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case NEEDS_REVIEW = 'needs_review';

    public function description(): string
    {
        return match ($this) {
            self::PENDING_REGISTRATION => 'Aguardando registro',
            self::REGISTERED => 'Registrada',
            self::REGISTRATION_FAILED => 'Falha no registro',
            self::PAID => 'Paga',
            self::CANCELED => 'Cancelada',
            self::EXPIRED => 'Expirada',
            self::NEEDS_REVIEW => 'Revisão necessária',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::REGISTERED => 'info',
            self::PAID => 'success',
            self::CANCELED, self::EXPIRED => 'gray',
            self::REGISTRATION_FAILED, self::NEEDS_REVIEW => 'danger',
            default => 'warning',
        };
    }
}
