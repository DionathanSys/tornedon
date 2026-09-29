<?php

namespace App\Enum\Financial;

enum BankSlipStatus: string
{
    case PENDING_REGISTRATION = 'pending_registration';
    case REGISTERED = 'registered';
    case REGISTRATION_FAILED = 'registration_failed';
    case UPDATE_PENDING = 'update_pending';
    case CANCEL_PENDING = 'cancel_pending';
    case CANCELED = 'canceled';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case PAYMENT_RETURNED = 'payment_returned';
    case NEEDS_REVIEW = 'needs_review';

    public function description(): string
    {
        return match ($this) {
            self::PENDING_REGISTRATION => 'Aguardando registro',
            self::REGISTERED => 'Registrado',
            self::REGISTRATION_FAILED => 'Falha no registro',
            self::UPDATE_PENDING => 'Atualizacao pendente',
            self::CANCEL_PENDING => 'Cancelamento pendente',
            self::CANCELED => 'Cancelado',
            self::PARTIALLY_PAID => 'Pago parcialmente',
            self::PAID => 'Pago',
            self::PAYMENT_RETURNED => 'Pagamento devolvido',
            self::NEEDS_REVIEW => 'Revisao necessaria',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::REGISTERED => 'info',
            self::PAID => 'success',
            self::PARTIALLY_PAID => 'warning',
            self::CANCELED, self::PAYMENT_RETURNED => 'gray',
            self::REGISTRATION_FAILED, self::NEEDS_REVIEW => 'danger',
            default => 'warning',
        };
    }
}
