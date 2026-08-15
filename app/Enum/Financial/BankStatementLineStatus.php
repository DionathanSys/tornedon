<?php

namespace App\Enum\Financial;

enum BankStatementLineStatus: string
{
    case PENDING = 'pending';
    case RECONCILED = 'reconciled';
    case IGNORED = 'ignored';
    case NEEDS_REVIEW = 'needs_review';
    case REVERSED = 'reversed';

    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RECONCILED => 'Conciliado',
            self::IGNORED => 'Ignorado',
            self::NEEDS_REVIEW => 'Revisão necessária',
            self::REVERSED => 'Estornado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RECONCILED => 'success',
            self::IGNORED => 'gray',
            self::NEEDS_REVIEW => 'danger',
            self::REVERSED => 'info',
        };
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::PENDING => in_array($newStatus, [self::RECONCILED, self::IGNORED, self::NEEDS_REVIEW], true),
            self::RECONCILED => in_array($newStatus, [self::NEEDS_REVIEW, self::REVERSED], true),
            self::IGNORED => in_array($newStatus, [self::PENDING, self::NEEDS_REVIEW], true),
            self::NEEDS_REVIEW => in_array($newStatus, [self::PENDING, self::RECONCILED, self::IGNORED, self::REVERSED], true),
            self::REVERSED => $newStatus === self::PENDING,
        };
    }

    public function canResolve(): bool
    {
        return $this === self::PENDING;
    }
}
