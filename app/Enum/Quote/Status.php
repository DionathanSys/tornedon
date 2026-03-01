<?php

namespace App\Enum\Quote;

enum Status: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case APPROVED = 'approved';
    case LINKED = 'linked';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SENT => 'Enviado',
            self::APPROVED => 'Aprovado',
            self::LINKED => 'Vinculado',
            self::REJECTED => 'Rejeitado',
            self::EXPIRED => 'Expirado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::SENT => 'info',
            self::APPROVED => 'success',
            self::LINKED => 'success',
            self::REJECTED => 'danger',
            self::EXPIRED => 'warning',
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
            self::DRAFT => in_array($newStatus, [self::SENT, self::REJECTED]),
            self::SENT => in_array($newStatus, [self::APPROVED, self::REJECTED, self::EXPIRED]),
            self::APPROVED => in_array($newStatus, [self::LINKED]),
            self::LINKED => false, // Estado final do item vinculado
            self::REJECTED => false,
            self::EXPIRED => false,
        };
    }
}
